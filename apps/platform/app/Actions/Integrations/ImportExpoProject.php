<?php

namespace App\Actions\Integrations;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\ExpoCredentialLink;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Credentials\CredentialUniqueness;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportExpoProject
{
    private const MAX_APP_ICON_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly CredentialUniqueness $credentialUniqueness,
        private readonly WebhookEventRecorder $webhookEvents,
    ) {}

    /**
     * Import an Expo project and the public metadata Expo exposes for its
     * identifiers and credentials. Private keys and credential files are
     * intentionally never requested from Expo.
     *
     * @param  array<string, mixed>  $remote
     * @return array{app: MobileApp, identifiers: int, credentials: int}
     */
    public function handle(
        Team $team,
        ExpoProject $project,
        array $remote,
        ?MobileApp $existingApp = null,
    ): array {
        $result = DB::transaction(function () use ($team, $project, $remote, $existingApp): array {
            $team = Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $project = ExpoProject::query()->lockForUpdate()->findOrFail($project->id);
            $appWasCreated = false;
            $identifierEvents = [];
            $credentialEvents = [];

            if ($existingApp) {
                abort_unless(
                    $project->mobile_app_id === $existingApp->id
                    && $existingApp->team_id === $team->id,
                    409,
                    'This Expo project is linked to another application.',
                );
                $app = $existingApp;
            } else {
                abort_if($project->mobile_app_id, 409, 'This Expo project is already linked.');
                $app = $team->mobileApps()->create([
                    'name' => $project->name,
                    'slug' => $this->availableSlug($team, $project),
                    'description' => $project->description,
                ]);
                $appWasCreated = true;

                $project->update([
                    'mobile_app_id' => $app->id,
                    'sync_metadata' => true,
                ]);
            }

            $identifiers = $this->importIdentifiers($app, $remote, $identifierEvents);
            $credentialIds = [];

            foreach ((array) ($remote['androidAppCredentials'] ?? []) as $appCredentials) {
                $identifier = $this->identifier(
                    $identifiers,
                    Platform::Android,
                    $appCredentials['applicationIdentifier'] ?? null,
                );
                if (! $identifier) {
                    continue;
                }

                $fcmKey = (array) ($appCredentials['googleServiceAccountKeyForFcmV1'] ?? []);
                if ($fcmKey !== []) {
                    $credential = $this->importCredential(
                        $team,
                        $project,
                        $identifier,
                        CredentialType::FirebaseConfig,
                        'Expo FCM service account',
                        'GoogleServiceAccountKey',
                        $fcmKey,
                        [],
                        [
                            'android_app_credentials_id' => $appCredentials['id'] ?? null,
                            'purpose' => 'fcm_v1',
                            'client_email' => $fcmKey['clientEmail'] ?? null,
                            'project_identifier' => $fcmKey['projectIdentifier'] ?? null,
                        ],
                        $credentialEvents,
                        fileContents: $fcmKey['keyJson'] ?? null,
                        fileName: 'expo-fcm-service-account.json',
                    );
                    $credentialIds[$credential->id] = true;
                }

                $submissionKey = (array) ($appCredentials['googleServiceAccountKeyForSubmissions'] ?? []);
                if ($submissionKey !== []) {
                    $credential = $this->importCredential(
                        $team,
                        $project,
                        $identifier,
                        CredentialType::GooglePlayServiceAccount,
                        'Expo Google Play service account',
                        'GoogleServiceAccountKey',
                        $submissionKey,
                        [],
                        [
                            'android_app_credentials_id' => $appCredentials['id'] ?? null,
                            'purpose' => 'submissions',
                            'client_email' => $submissionKey['clientEmail'] ?? null,
                            'project_identifier' => $submissionKey['projectIdentifier'] ?? null,
                        ],
                        $credentialEvents,
                        fileContents: $submissionKey['keyJson'] ?? null,
                        fileName: 'expo-google-play-service-account.json',
                    );
                    $credentialIds[$credential->id] = true;
                }

                foreach ((array) ($appCredentials['androidAppBuildCredentialsList'] ?? []) as $buildCredentials) {
                    $keystore = (array) ($buildCredentials['androidKeystore'] ?? []);
                    if ($keystore === []) {
                        continue;
                    }

                    $credential = $this->importCredential(
                        $team,
                        $project,
                        $identifier,
                        CredentialType::AndroidKeystore,
                        'Expo Android keystore'.(
                            ! empty($buildCredentials['name']) ? ': '.$buildCredentials['name'] : ''
                        ),
                        'AndroidKeystore',
                        $keystore,
                        array_filter([
                            'store_password' => $keystore['keystorePassword'] ?? null,
                            'key_alias' => $keystore['keyAlias'] ?? null,
                            'key_password' => $keystore['keyPassword']
                                ?? $keystore['keystorePassword']
                                ?? null,
                        ], fn ($value) => $value !== null && $value !== ''),
                        [
                            'android_app_credentials_id' => $appCredentials['id'] ?? null,
                            'android_app_build_credentials_id' => $buildCredentials['id'] ?? null,
                            'build_credentials_name' => $buildCredentials['name'] ?? null,
                            'is_default' => $buildCredentials['isDefault'] ?? false,
                            'md5_fingerprint' => $keystore['md5CertificateFingerprint'] ?? null,
                            'sha1_fingerprint' => $keystore['sha1CertificateFingerprint'] ?? null,
                            'sha256_fingerprint' => $keystore['sha256CertificateFingerprint'] ?? null,
                        ],
                        $credentialEvents,
                        ProfileType::Production,
                        fileContents: $keystore['keystore'] ?? null,
                        fileName: 'expo-android-keystore.jks',
                        base64Encoded: true,
                    );
                    $credentialIds[$credential->id] = true;
                }
            }

            foreach ((array) ($remote['iosAppCredentials'] ?? []) as $appCredentials) {
                $appleIdentifier = (array) ($appCredentials['appleAppIdentifier'] ?? []);
                $identifier = $this->identifier(
                    $identifiers,
                    Platform::Ios,
                    $appleIdentifier['bundleIdentifier'] ?? null,
                );
                if (! $identifier) {
                    continue;
                }

                $appleTeam = (array) ($appCredentials['appleTeam'] ?? []);
                $pushKey = (array) ($appCredentials['pushKey'] ?? []);
                if ($pushKey !== []) {
                    $pushKeyAppleTeam = array_replace(
                        $appleTeam,
                        array_filter(
                            (array) ($pushKey['appleTeam'] ?? []),
                            fn ($value) => $value !== null && $value !== '',
                        ),
                    );
                    $credential = $this->importCredential(
                        $team,
                        $project,
                        $identifier,
                        CredentialType::ApnsKey,
                        'Expo APNs key',
                        'ApplePushKey',
                        $pushKey,
                        array_filter([
                            'key_id' => $pushKey['keyIdentifier'] ?? null,
                            'team_id' => $pushKeyAppleTeam['appleTeamIdentifier'] ?? null,
                        ], fn ($value) => $value !== null && $value !== ''),
                        [
                            'ios_app_credentials_id' => $appCredentials['id'] ?? null,
                            'apple_team_id' => $pushKeyAppleTeam['id'] ?? null,
                            'apple_team_name' => $pushKeyAppleTeam['appleTeamName'] ?? null,
                        ],
                        $credentialEvents,
                        fileContents: $pushKey['keyP8'] ?? null,
                        fileName: 'expo-apns-key.p8',
                    );
                    $credentialIds[$credential->id] = true;
                }

                $ascKey = (array) ($appCredentials['appStoreConnectApiKeyForSubmissions'] ?? []);
                if ($ascKey !== []) {
                    $credential = $this->importCredential(
                        $team,
                        $project,
                        $identifier,
                        CredentialType::AppleApiKey,
                        'Expo App Store Connect key'.(! empty($ascKey['name']) ? ': '.$ascKey['name'] : ''),
                        'AppStoreConnectApiKey',
                        $ascKey,
                        array_filter([
                            'key_id' => $ascKey['keyIdentifier'] ?? null,
                            'issuer_id' => $ascKey['issuerIdentifier'] ?? null,
                        ], fn ($value) => $value !== null && $value !== ''),
                        [
                            'ios_app_credentials_id' => $appCredentials['id'] ?? null,
                            'purpose' => 'submissions',
                        ],
                        $credentialEvents,
                        fileContents: $ascKey['keyP8'] ?? null,
                        fileName: 'expo-app-store-connect-key.p8',
                    );
                    $credentialIds[$credential->id] = true;
                }

                foreach ((array) ($appCredentials['iosAppBuildCredentialsList'] ?? []) as $buildCredentials) {
                    $profileType = $this->iosProfileType(
                        (string) ($buildCredentials['iosDistributionType'] ?? ''),
                    );
                    $certificate = (array) ($buildCredentials['distributionCertificate'] ?? []);
                    if ($certificate !== []) {
                        $credential = $this->importCredential(
                            $team,
                            $project,
                            $identifier,
                            CredentialType::IosCertificate,
                            'Expo iOS distribution certificate',
                            'AppleDistributionCertificate',
                            $certificate,
                            array_filter([
                                'password' => $certificate['certificatePassword'] ?? null,
                            ], fn ($value) => $value !== null),
                            [
                                'ios_app_credentials_id' => $appCredentials['id'] ?? null,
                                'ios_app_build_credentials_id' => $buildCredentials['id'] ?? null,
                                'distribution_type' => $buildCredentials['iosDistributionType'] ?? null,
                                'serial_number' => $certificate['serialNumber'] ?? null,
                            ],
                            $credentialEvents,
                            $profileType,
                            $certificate['validityNotAfter'] ?? null,
                            fileContents: $certificate['certificateP12'] ?? null,
                            fileName: 'expo-distribution-certificate.p12',
                            base64Encoded: true,
                        );
                        $credentialIds[$credential->id] = true;
                    }

                    $provisioningProfile = (array) ($buildCredentials['provisioningProfile'] ?? []);
                    if ($provisioningProfile !== []) {
                        $credential = $this->importCredential(
                            $team,
                            $project,
                            $identifier,
                            CredentialType::ProvisioningProfile,
                            'Expo iOS provisioning profile',
                            'AppleProvisioningProfile',
                            $provisioningProfile,
                            [],
                            [
                                'ios_app_credentials_id' => $appCredentials['id'] ?? null,
                                'ios_app_build_credentials_id' => $buildCredentials['id'] ?? null,
                                'distribution_type' => $buildCredentials['iosDistributionType'] ?? null,
                                'apple_uuid' => $provisioningProfile['appleUUID'] ?? null,
                                'status' => $provisioningProfile['status'] ?? null,
                            ],
                            $credentialEvents,
                            $profileType,
                            $provisioningProfile['expiration'] ?? null,
                            fileContents: $provisioningProfile['provisioningProfile'] ?? null,
                            fileName: 'expo-provisioning-profile.mobileprovision',
                            base64Encoded: true,
                        );
                        $credentialIds[$credential->id] = true;
                    }
                }
            }

            if ($appWasCreated) {
                $this->webhookEvents->record(
                    $team,
                    WebhookEventType::MobileAppCreated,
                    $app,
                    WebhookPayload::mobileApp($app),
                );
            }

            foreach ($identifierEvents as $event) {
                $this->webhookEvents->record(
                    $team,
                    $event['type'],
                    $event['identifier'],
                    WebhookPayload::appIdentifier($event['identifier']),
                );
            }

            foreach ($credentialEvents as $event) {
                $credential = $event['credential'];
                $credential->unsetRelation('appIdentifiers');
                $credential->unsetRelation('profiles');
                $this->webhookEvents->record(
                    $team,
                    $event['type'],
                    $credential,
                    WebhookPayload::credential($credential),
                );
            }

            return [
                'app' => $app,
                'identifiers' => count($identifiers),
                'credentials' => count($credentialIds),
            ];
        });

        if (! $result['app']->hasMedia('app_icon')) {
            $this->importAppIcon($result['app'], $project, $remote);
        }

        return $result;
    }

    /** @param array<string, mixed> $remote */
    private function importAppIcon(
        MobileApp $app,
        ExpoProject $project,
        array $remote,
    ): void {
        $url = trim((string) ($remote['profileImageUrl'] ?? $project->icon_url ?? ''));
        if (! $this->isAllowedIconUrl($url)) {
            return;
        }

        try {
            $response = Http::accept('image/*')
                ->connectTimeout(5)
                ->timeout(15)
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (ConnectionException) {
            return;
        }

        $contentLength = (int) $response->header('Content-Length');
        $contents = $response->body();
        if (! $response->successful()
            || $contentLength > self::MAX_APP_ICON_BYTES
            || strlen($contents) > self::MAX_APP_ICON_BYTES) {
            return;
        }

        $image = @getimagesizefromstring($contents);
        $extension = match ($image['mime'] ?? null) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
        if (! $extension) {
            return;
        }

        try {
            $app->addMediaFromString($contents)
                ->usingFileName("expo-app-icon.{$extension}")
                ->toMediaCollection('app_icon');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function isAllowedIconUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! filled($parts['host'] ?? null)
            || (isset($parts['port']) && $parts['port'] !== 443)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return false;
        }

        return ! filter_var($host, FILTER_VALIDATE_IP)
            || filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
    }

    private function availableSlug(Team $team, ExpoProject $project): string
    {
        $baseSlug = Str::slug($project->slug ?: $project->name) ?: 'app';
        $slug = $baseSlug;
        $suffix = 2;

        while ($team->mobileApps()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  array<string, array{type: WebhookEventType, identifier: AppIdentifier}>  $webhookEvents
     * @return array<string, AppIdentifier>
     */
    private function importIdentifiers(
        MobileApp $app,
        array $remote,
        array &$webhookEvents,
    ): array {
        $candidates = [];

        foreach ((array) ($remote['androidAppCredentials'] ?? []) as $credentials) {
            $this->addIdentifierCandidate(
                $candidates,
                Platform::Android,
                $credentials['applicationIdentifier'] ?? null,
            );
        }

        foreach ((array) ($remote['iosAppCredentials'] ?? []) as $credentials) {
            $this->addIdentifierCandidate(
                $candidates,
                Platform::Ios,
                data_get($credentials, 'appleAppIdentifier.bundleIdentifier'),
            );
        }

        foreach ((array) ($remote['builds'] ?? []) as $build) {
            $platform = match (strtoupper((string) ($build['platform'] ?? ''))) {
                'ANDROID' => Platform::Android,
                'IOS' => Platform::Ios,
                default => null,
            };
            if (! $platform) {
                continue;
            }

            $this->addIdentifierCandidate(
                $candidates,
                $platform,
                $build['appIdentifier'] ?? null,
                $build['appVersion'] ?? null,
                $build['appBuildVersion'] ?? null,
            );
        }

        return collect($candidates)
            ->mapWithKeys(function (array $candidate, string $key) use ($app, &$webhookEvents): array {
                $identifier = $app->appIdentifiers()->firstOrNew([
                    'platform' => $candidate['platform'],
                    'identifier' => $candidate['identifier'],
                ]);
                $wasCreated = ! $identifier->exists;
                if ($wasCreated) {
                    $identifier->name = $candidate['platform'] === Platform::Ios ? 'iOS' : 'Android';
                }
                if ($candidate['version'] !== null) {
                    $identifier->version = $candidate['version'];
                }
                if ($candidate['version_code'] !== null) {
                    $identifier->version_code = $candidate['version_code'];
                }
                $identifier->save();

                if ($wasCreated || $identifier->wasChanged(['name', 'version', 'version_code'])) {
                    $webhookEvents[$identifier->id] = [
                        'type' => $wasCreated
                            ? WebhookEventType::AppIdentifierCreated
                            : WebhookEventType::AppIdentifierUpdated,
                        'identifier' => $identifier,
                    ];
                }

                return [$key => $identifier];
            })
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $candidates
     */
    private function addIdentifierCandidate(
        array &$candidates,
        Platform $platform,
        mixed $identifier,
        mixed $version = null,
        mixed $versionCode = null,
    ): void {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return;
        }

        $key = $platform->value.'|'.$identifier;
        $candidate = $candidates[$key] ?? [
            'platform' => $platform,
            'identifier' => $identifier,
            'version' => null,
            'version_code' => null,
        ];
        $candidate['version'] ??= filled($version) ? (string) $version : null;
        if ($platform === Platform::Android && filled($versionCode)) {
            $candidate['version_code'] ??= ctype_digit((string) $versionCode)
                ? (int) $versionCode
                : null;
        }
        $candidates[$key] = $candidate;
    }

    /**
     * @param  array<string, AppIdentifier>  $identifiers
     */
    private function identifier(
        array $identifiers,
        Platform $platform,
        mixed $identifier,
    ): ?AppIdentifier {
        $identifier = trim((string) $identifier);

        return $identifier === '' ? null : ($identifiers[$platform->value.'|'.$identifier] ?? null);
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     * @param  array<string, array{type: WebhookEventType, credential: Credential}>  $webhookEvents
     */
    private function importCredential(
        Team $team,
        ExpoProject $project,
        AppIdentifier $identifier,
        CredentialType $type,
        string $name,
        string $remoteType,
        array $remote,
        array $payload,
        array $metadata,
        array &$webhookEvents,
        ?ProfileType $profileType = null,
        mixed $expiresAt = null,
        mixed $fileContents = null,
        ?string $fileName = null,
        bool $base64Encoded = false,
    ): Credential {
        $remoteId = trim((string) ($remote['id'] ?? ''));
        abort_if($remoteId === '', 422, "Expo returned {$remoteType} without an ID.");
        $contents = $this->credentialFileContents(
            $type,
            $fileContents,
            $base64Encoded,
        );
        $fingerprint = $contents !== null && $fileName
            ? $this->credentialUniqueness->fingerprintContents($type, $payload, $contents)
            : null;

        $credential = ExpoCredentialLink::query()
            ->with('credential')
            ->where('expo_project_id', $project->id)
            ->where('remote_resource_type', $remoteType)
            ->where('remote_resource_id', $remoteId)
            ->whereHas('credential', fn ($query) => $query->where('type', $type->value))
            ->first()
            ?->credential;

        if (! $credential
            && $team->check_credential_uniqueness_by_default
            && $fingerprint !== null) {
            $credential = $this->credentialUniqueness->findMatching(
                $team,
                $type,
                $fingerprint,
            );
        }

        $wasCreated = false;
        $originalState = $credential ? $this->credentialState($credential) : null;

        if (! $credential) {
            $credential = $team->credentials()->create([
                'type' => $type,
                'name' => Str::limit($name, 255, ''),
                'payload' => $payload,
                'expires_at' => filled($expiresAt) ? $expiresAt : null,
                'fingerprint' => $fingerprint,
                'tags' => [
                    ['Imported from Expo', '#6366f1'],
                    ['Metadata only', '#f59e0b'],
                ],
            ]);
            $wasCreated = true;
        }
        $credential->update([
            'payload' => [...($credential->payload ?? []), ...$payload],
            'expires_at' => filled($expiresAt) ? $expiresAt : $credential->expires_at,
        ]);

        $secretSync = $this->syncCredentialFile(
            $credential,
            $contents,
            $fileName,
        );
        $this->syncExpoTags($credential, $secretSync['synced']);
        $credential->forceFill([
            'fingerprint' => $fingerprint
                ?? $this->credentialUniqueness->fingerprintCredential($credential),
        ])->saveQuietly();

        if ($profileType) {
            $profile = $identifier->profiles()->firstOrCreate(
                ['type' => $profileType],
                ['name' => $this->profileName($profileType)],
            );
            $attachmentChanges = $profile->credentials()->syncWithoutDetaching([$credential->id]);
        } else {
            $attachmentChanges = $identifier->credentials()->syncWithoutDetaching([$credential->id]);
        }

        $link = ExpoCredentialLink::query()->updateOrCreate(
            [
                'expo_project_id' => $project->id,
                'credential_id' => $credential->id,
                'app_identifier_id' => $identifier->id,
            ],
            [
                'integration_id' => $project->integration_id,
                'remote_resource_type' => $remoteType,
                'remote_resource_id' => $remoteId,
                'metadata' => array_filter(
                    [...$metadata, 'remote_updated_at' => $remote['updatedAt'] ?? null],
                    fn ($value) => $value !== null,
                ),
                'last_synced_at' => now(),
            ],
        );

        $changed = $wasCreated
            || $originalState !== $this->credentialState($credential)
            || $secretSync['changed']
            || $attachmentChanges['attached'] !== []
            || $attachmentChanges['updated'] !== []
            || $link->wasRecentlyCreated
            || $link->wasChanged([
                'integration_id',
                'remote_resource_type',
                'remote_resource_id',
                'metadata',
            ]);

        if ($changed) {
            $this->trackCredentialWebhookEvent(
                $webhookEvents,
                $credential,
                $wasCreated
                    ? WebhookEventType::CredentialCreated
                    : WebhookEventType::CredentialUpdated,
            );
        }

        return $credential;
    }

    private function credentialFileContents(
        CredentialType $type,
        mixed $fileContents,
        bool $base64Encoded,
    ): ?string {
        if (! is_string($fileContents) || $fileContents === '') {
            return null;
        }

        if (! $base64Encoded) {
            return $fileContents;
        }

        $decoded = base64_decode($fileContents, true);
        if ($decoded === false) {
            throw new RuntimeException(
                "Expo returned invalid base64 data for {$type->value}.",
            );
        }

        return $decoded;
    }

    /** @return array{synced: bool, changed: bool} */
    private function syncCredentialFile(
        Credential $credential,
        ?string $contents,
        ?string $fileName,
    ): array {
        if ($contents === null || ! $fileName) {
            return [
                'synced' => $credential->hasMedia('file')
                    && $credential->hasTag('Secret synced from Expo'),
                'changed' => false,
            ];
        }

        $media = $credential->getFirstMedia('file');
        if ($media) {
            $stored = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
            if (hash_equals(hash('sha256', $stored), hash('sha256', $contents))) {
                return ['synced' => true, 'changed' => false];
            }
        }

        $credential->addMediaFromString($contents)
            ->usingFileName($fileName)
            ->toMediaCollection('file');

        return ['synced' => true, 'changed' => true];
    }

    /** @return array<string, mixed> */
    private function credentialState(Credential $credential): array
    {
        return [
            'name' => $credential->name,
            'type' => $credential->type->value,
            'payload' => $credential->payload ?? [],
            'expires_at' => $credential->expires_at?->toISOString(),
            'tags' => $credential->tags ?? [],
            'fingerprint' => $credential->fingerprint,
        ];
    }

    /**
     * @param  array<string, array{type: WebhookEventType, credential: Credential}>  $events
     */
    private function trackCredentialWebhookEvent(
        array &$events,
        Credential $credential,
        WebhookEventType $type,
    ): void {
        $current = $events[$credential->id]['type'] ?? null;
        if ($current === WebhookEventType::CredentialCreated) {
            return;
        }

        $events[$credential->id] = [
            'type' => $type,
            'credential' => $credential,
        ];
    }

    private function syncExpoTags(Credential $credential, bool $secretSynced): void
    {
        $managedTags = ['imported from expo', 'metadata only', 'secret synced from expo'];
        $tags = collect($credential->tags ?? [])
            ->reject(fn ($tag) => in_array(
                mb_strtolower((string) ($tag[0] ?? '')),
                $managedTags,
                true,
            ))
            ->values()
            ->all();
        $credential->syncTags([
            ...$tags,
            ['Imported from Expo', '#6366f1'],
            $secretSynced
                ? ['Secret synced from Expo', '#10b981']
                : ['Metadata only', '#f59e0b'],
        ])->save();
    }

    private function iosProfileType(string $distributionType): ProfileType
    {
        return match (strtoupper($distributionType)) {
            'AD_HOC' => ProfileType::AdHoc,
            'DEVELOPMENT' => ProfileType::Development,
            'ENTERPRISE' => ProfileType::Enterprise,
            default => ProfileType::AppStore,
        };
    }

    private function profileName(ProfileType $type): string
    {
        return match ($type) {
            ProfileType::AppStore => 'App Store',
            ProfileType::AdHoc => 'Ad Hoc',
            ProfileType::Development => 'Development',
            ProfileType::Enterprise => 'Enterprise',
            ProfileType::Production => 'Production',
            ProfileType::Preview => 'Preview',
        };
    }
}
