<?php

namespace App\Integrations\Expo;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\ExpoCredentialLink;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use App\Support\Credentials\CredentialFileStorage;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class ExpoCredentialExporter
{
    public function __construct(private readonly ExpoClient $expo) {}

    /** @return array<string, mixed> */
    public function export(
        ExpoProject $project,
        AppIdentifier $identifier,
        Credential $credential,
    ): array {
        $result = match ($credential->type) {
            CredentialType::AndroidKeystore => $this->androidKeystore($project, $identifier, $credential),
            CredentialType::FirebaseConfig => $this->fcmV1ServiceAccount(
                $project,
                $identifier,
                $credential,
            ),
            CredentialType::GooglePlayServiceAccount => $this->googleServiceAccount(
                $project,
                $identifier,
                $credential,
            ),
            CredentialType::ApnsKey => $this->applePushKey($project, $identifier, $credential),
            CredentialType::AppleApiKey => $this->appStoreConnectApiKey($project, $identifier, $credential),
            CredentialType::IosCertificate => $this->appleDistributionCertificate($project, $identifier, $credential),
            CredentialType::ProvisioningProfile => $this->appleProvisioningProfile($project, $identifier, $credential),
            default => throw new RuntimeException(
                'This credential type is not stored by EAS and cannot be pushed to Expo.',
            ),
        };

        $link = DB::transaction(function () use ($project, $identifier, $credential, $result) {
            $link = ExpoCredentialLink::query()->updateOrCreate(
                [
                    'expo_project_id' => $project->id,
                    'credential_id' => $credential->id,
                    'app_identifier_id' => $identifier->id,
                ],
                [
                    'integration_id' => $project->integration_id,
                    'remote_resource_type' => $result['type'],
                    'remote_resource_id' => $result['id'],
                    'metadata' => $result['metadata'] ?? [],
                    'last_synced_at' => now(),
                ],
            );

            if (in_array($credential->type, [
                CredentialType::IosCertificate,
                CredentialType::ProvisioningProfile,
            ], true)) {
                $this->linkIosBuildCredentials($project, $identifier);
            }

            return $link->refresh();
        });

        return [
            'link' => $link,
            'remote' => $result,
        ];
    }

    /** @return array{identifiers: int, credentials: int, errors: array<int, string>} */
    public function exportApplication(ExpoProject $project, MobileApp $app): array
    {
        $app->loadMissing([
            'appIdentifiers.credentials.media',
            'appIdentifiers.profiles.credentials.media',
        ]);
        $result = [
            'identifiers' => 0,
            'credentials' => 0,
            'errors' => [],
        ];

        foreach ($app->appIdentifiers as $identifier) {
            if ($identifier->platform === Platform::Web) {
                continue;
            }

            try {
                $this->provisionIdentifier($project, $identifier);
                $result['identifiers']++;
            } catch (ExpoGraphqlException|RuntimeException $exception) {
                $result['errors'][] = "{$identifier->identifier}: {$exception->getMessage()}";
            }

            $credentials = $identifier->credentials
                ->concat($identifier->profiles->flatMap(
                    fn ($profile) => $profile->credentials,
                ))
                ->unique('id')
                ->filter(fn (Credential $credential) => self::supports($credential->type)
                    && $credential->getFirstMedia('file') !== null)
                ->sortBy(fn (Credential $credential) => match ($credential->type) {
                    CredentialType::IosCertificate => 10,
                    CredentialType::ProvisioningProfile => 20,
                    default => 0,
                });

            foreach ($credentials as $credential) {
                try {
                    $this->export($project, $identifier, $credential);
                    $result['credentials']++;
                } catch (ExpoGraphqlException|RuntimeException $exception) {
                    $result['errors'][] = "{$credential->name}: {$exception->getMessage()}";
                }
            }
        }

        return $result;
    }

    /** @return array{type: string, id: string} */
    public function provisionIdentifier(
        ExpoProject $project,
        AppIdentifier $identifier,
    ): array {
        return match ($identifier->platform) {
            Platform::Android => [
                'type' => 'AndroidAppCredentials',
                'id' => $this->ensureAndroidAppCredentials($project, $identifier),
            ],
            Platform::Ios => [
                'type' => 'IosAppCredentials',
                'id' => $this->ensureIosAppCredentials($project, $identifier),
            ],
            Platform::Web => throw new RuntimeException(
                'Web identifiers are not managed by Expo credentials.',
            ),
        };
    }

    public static function supports(CredentialType $type): bool
    {
        return in_array($type, [
            CredentialType::AndroidKeystore,
            CredentialType::FirebaseConfig,
            CredentialType::GooglePlayServiceAccount,
            CredentialType::ApnsKey,
            CredentialType::AppleApiKey,
            CredentialType::IosCertificate,
            CredentialType::ProvisioningProfile,
        ], true);
    }

    /** @return array<string, mixed> */
    private function androidKeystore(
        ExpoProject $project,
        AppIdentifier $identifier,
        Credential $credential,
    ): array {
        $payload = $credential->payload ?? [];
        $data = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateAndroidKeystore($accountId: ID!, $input: AndroidKeystoreInput!) {
              androidKeystore {
                createAndroidKeystore(accountId: $accountId, androidKeystoreInput: $input) {
                  id
                  keyAlias
                  sha256CertificateFingerprint
                }
              }
            }
            GRAPHQL, [
            'accountId' => $project->account_id,
            'input' => [
                'base64EncodedKeystore' => base64_encode($this->fileContents($credential)),
                'keystorePassword' => (string) ($payload['store_password'] ?? ''),
                'keyAlias' => (string) ($payload['key_alias'] ?? ''),
                'keyPassword' => (string) ($payload['key_password'] ?? ''),
            ],
        ]);
        $keystore = (array) data_get($data, 'androidKeystore.createAndroidKeystore', []);
        $keystoreId = $this->remoteId($keystore, 'Android keystore');
        $appCredentialsId = $this->ensureAndroidAppCredentials($project, $identifier);
        $buildData = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateAndroidBuildCredentials(
              $androidAppCredentialsId: ID!,
              $input: AndroidAppBuildCredentialsInput!
            ) {
              androidAppBuildCredentials {
                createAndroidAppBuildCredentials(
                  androidAppCredentialsId: $androidAppCredentialsId,
                  androidAppBuildCredentialsInput: $input
                ) {
                  id
                  name
                  isDefault
                }
              }
            }
            GRAPHQL, [
            'androidAppCredentialsId' => $appCredentialsId,
            'input' => [
                'isDefault' => true,
                'keystoreId' => $keystoreId,
                'name' => $credential->name,
            ],
        ]);
        $buildCredentials = (array) data_get(
            $buildData,
            'androidAppBuildCredentials.createAndroidAppBuildCredentials',
            [],
        );
        $buildCredentialsId = $this->remoteId(
            $buildCredentials,
            'Android build credentials',
        );

        return [
            'type' => 'AndroidKeystore',
            'id' => $keystoreId,
            'metadata' => [
                'android_app_credentials_id' => $appCredentialsId,
                'android_app_build_credentials_id' => $buildCredentialsId,
                'key_alias' => $keystore['keyAlias'] ?? null,
                'sha256_fingerprint' => $keystore['sha256CertificateFingerprint'] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function fcmV1ServiceAccount(
        ExpoProject $project,
        AppIdentifier $identifier,
        Credential $credential,
    ): array {
        try {
            $credentialJson = $this->fileContents($credential);
            json_decode($credentialJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The credential file is not valid service-account JSON.');
        }

        $appCredentialsId = $this->ensureAndroidAppCredentials($project, $identifier);
        $data = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateFcmV1Credential(
              $accountId: ID!,
              $androidAppCredentialsId: String!,
              $credential: String!
            ) {
              androidAppCredentials {
                createFcmV1Credential(
                  accountId: $accountId,
                  androidAppCredentialsId: $androidAppCredentialsId,
                  credential: $credential
                ) {
                  id
                  googleServiceAccountKeyForFcmV1 {
                    id
                    clientEmail
                    projectIdentifier
                  }
                }
              }
            }
            GRAPHQL, [
            'accountId' => $project->account_id,
            'androidAppCredentialsId' => $appCredentialsId,
            'credential' => $credentialJson,
        ]);
        $appCredentials = (array) data_get(
            $data,
            'androidAppCredentials.createFcmV1Credential',
            [],
        );
        $key = (array) ($appCredentials['googleServiceAccountKeyForFcmV1'] ?? []);
        $keyId = $this->remoteId($key, 'FCM HTTP v1 service-account key');

        return [
            'type' => 'GoogleServiceAccountKey',
            'id' => $keyId,
            'metadata' => [
                'android_app_credentials_id' => $this->remoteId(
                    $appCredentials,
                    'Android app credentials',
                ),
                'purpose' => 'fcm_v1',
                'client_email' => $key['clientEmail'] ?? null,
                'project_identifier' => $key['projectIdentifier'] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function googleServiceAccount(
        ExpoProject $project,
        AppIdentifier $identifier,
        Credential $credential,
    ): array {
        try {
            $json = json_decode($this->fileContents($credential), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The credential file is not valid service-account JSON.');
        }

        $data = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateGoogleServiceAccountKey(
              $accountId: ID!,
              $input: GoogleServiceAccountKeyInput!
            ) {
              googleServiceAccountKey {
                createGoogleServiceAccountKey(
                  accountId: $accountId,
                  googleServiceAccountKeyInput: $input
                ) {
                  id
                  clientEmail
                  projectIdentifier
                }
              }
            }
            GRAPHQL, [
            'accountId' => $project->account_id,
            'input' => ['jsonKey' => $json],
        ]);
        $key = (array) data_get(
            $data,
            'googleServiceAccountKey.createGoogleServiceAccountKey',
            [],
        );
        $keyId = $this->remoteId($key, 'Google service-account key');
        $appCredentialsId = $this->ensureAndroidAppCredentials($project, $identifier);
        $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerAttachGoogleServiceAccountKey($id: ID!, $keyId: ID!) {
              androidAppCredentials {
                setGoogleServiceAccountKeyForSubmissions(
                  id: $id,
                  googleServiceAccountKeyId: $keyId
                ) {
                  id
                }
              }
            }
            GRAPHQL, [
            'id' => $appCredentialsId,
            'keyId' => $keyId,
        ]);

        return [
            'type' => 'GoogleServiceAccountKey',
            'id' => $keyId,
            'metadata' => [
                'android_app_credentials_id' => $appCredentialsId,
                'purpose' => 'submissions',
                'client_email' => $key['clientEmail'] ?? null,
                'project_identifier' => $key['projectIdentifier'] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function applePushKey(
        ExpoProject $project,
        AppIdentifier $identifier,
        Credential $credential,
    ): array {
        $payload = $credential->payload ?? [];
        $teamId = (string) ($payload['team_id'] ?? '');
        $appleTeam = $this->ensureAppleTeam($project, $teamId);
        $data = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateApplePushKey($accountId: ID!, $input: ApplePushKeyInput!) {
              applePushKey {
                createApplePushKey(accountId: $accountId, applePushKeyInput: $input) {
                  id
                  keyIdentifier
                }
              }
            }
            GRAPHQL, [
            'accountId' => $project->account_id,
            'input' => [
                'appleTeamId' => (string) $appleTeam['id'],
                'keyIdentifier' => (string) ($payload['key_id'] ?? ''),
                'keyP8' => $this->fileContents($credential),
            ],
        ]);
        $key = (array) data_get($data, 'applePushKey.createApplePushKey', []);
        $keyId = $this->remoteId($key, 'Apple push key');
        $iosCredentialsId = $this->ensureIosAppCredentials(
            $project,
            $identifier,
            (string) $appleTeam['id'],
        );
        $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerAttachApplePushKey($id: ID!, $pushKeyId: ID!) {
              iosAppCredentials {
                setPushKey(id: $id, pushKeyId: $pushKeyId) {
                  id
                }
              }
            }
            GRAPHQL, [
            'id' => $iosCredentialsId,
            'pushKeyId' => $keyId,
        ]);

        return [
            'type' => 'ApplePushKey',
            'id' => $keyId,
            'metadata' => [
                'ios_app_credentials_id' => $iosCredentialsId,
                'key_identifier' => $key['keyIdentifier'] ?? null,
                'apple_team_id' => $appleTeam['id'] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function appStoreConnectApiKey(
        ExpoProject $project,
        AppIdentifier $identifier,
        Credential $credential,
    ): array {
        $payload = $credential->payload ?? [];
        $data = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateAscApiKey($accountId: ID!, $input: AppStoreConnectApiKeyInput!) {
              appStoreConnectApiKey {
                createAppStoreConnectApiKey(accountId: $accountId, appStoreConnectApiKeyInput: $input) {
                  id
                  name
                  keyIdentifier
                  issuerIdentifier
                }
              }
            }
            GRAPHQL, [
            'accountId' => $project->account_id,
            'input' => [
                'issuerIdentifier' => (string) ($payload['issuer_id'] ?? ''),
                'keyIdentifier' => (string) ($payload['key_id'] ?? ''),
                'keyP8' => $this->fileContents($credential),
                'name' => $credential->name,
            ],
        ]);
        $key = (array) data_get(
            $data,
            'appStoreConnectApiKey.createAppStoreConnectApiKey',
            [],
        );
        $keyId = $this->remoteId($key, 'App Store Connect API key');
        $iosCredentialsId = $this->ensureIosAppCredentials($project, $identifier);
        $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerAttachAscApiKey($id: ID!, $keyId: ID!) {
              iosAppCredentials {
                setAppStoreConnectApiKeyForSubmissions(id: $id, ascApiKeyId: $keyId) {
                  id
                }
              }
            }
            GRAPHQL, [
            'id' => $iosCredentialsId,
            'keyId' => $keyId,
        ]);

        return [
            'type' => 'AppStoreConnectApiKey',
            'id' => $keyId,
            'metadata' => [
                'ios_app_credentials_id' => $iosCredentialsId,
                'key_identifier' => $key['keyIdentifier'] ?? null,
                'issuer_identifier' => $key['issuerIdentifier'] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function appleDistributionCertificate(
        ExpoProject $project,
        AppIdentifier $identifier,
        Credential $credential,
    ): array {
        $payload = $credential->payload ?? [];
        $data = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateAppleDistributionCertificate(
              $accountId: ID!,
              $input: AppleDistributionCertificateInput!
            ) {
              appleDistributionCertificate {
                createAppleDistributionCertificate(
                  accountId: $accountId,
                  appleDistributionCertificateInput: $input
                ) {
                  id
                  serialNumber
                  validityNotAfter
                }
              }
            }
            GRAPHQL, [
            'accountId' => $project->account_id,
            'input' => [
                'certP12' => base64_encode($this->fileContents($credential)),
                'certPassword' => (string) ($payload['password'] ?? ''),
            ],
        ]);
        $certificate = (array) data_get(
            $data,
            'appleDistributionCertificate.createAppleDistributionCertificate',
            [],
        );
        $certificateId = $this->remoteId($certificate, 'Apple distribution certificate');

        return [
            'type' => 'AppleDistributionCertificate',
            'id' => $certificateId,
            'metadata' => [
                'serial_number' => $certificate['serialNumber'] ?? null,
                'expires_at' => $certificate['validityNotAfter'] ?? null,
                'profile_type' => $this->profileType($credential, $identifier)?->value,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function appleProvisioningProfile(
        ExpoProject $project,
        AppIdentifier $identifier,
        Credential $credential,
    ): array {
        $appleIdentifier = $this->ensureAppleAppIdentifier($project, $identifier);
        $data = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateAppleProvisioningProfile(
              $accountId: ID!,
              $appleAppIdentifierId: ID!,
              $input: AppleProvisioningProfileInput!
            ) {
              appleProvisioningProfile {
                createAppleProvisioningProfile(
                  accountId: $accountId,
                  appleAppIdentifierId: $appleAppIdentifierId,
                  appleProvisioningProfileInput: $input
                ) {
                  id
                  appleUUID
                  expiration
                  status
                }
              }
            }
            GRAPHQL, [
            'accountId' => $project->account_id,
            'appleAppIdentifierId' => (string) $appleIdentifier['id'],
            'input' => [
                'appleProvisioningProfile' => base64_encode($this->fileContents($credential)),
            ],
        ]);
        $profile = (array) data_get(
            $data,
            'appleProvisioningProfile.createAppleProvisioningProfile',
            [],
        );
        $profileId = $this->remoteId($profile, 'Apple provisioning profile');

        return [
            'type' => 'AppleProvisioningProfile',
            'id' => $profileId,
            'metadata' => [
                'apple_uuid' => $profile['appleUUID'] ?? null,
                'expires_at' => $profile['expiration'] ?? null,
                'status' => $profile['status'] ?? null,
                'profile_type' => $this->profileType($credential, $identifier)?->value,
            ],
        ];
    }

    private function ensureAndroidAppCredentials(
        ExpoProject $project,
        AppIdentifier $identifier,
    ): string {
        $remote = $this->expo->project($project->integration, $project->external_id);
        $existing = collect((array) ($remote['androidAppCredentials'] ?? []))
            ->firstWhere('applicationIdentifier', $identifier->identifier);

        if (is_array($existing)) {
            return $this->remoteId($existing, 'Android app credentials');
        }

        $data = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateAndroidAppCredentials(
              $appId: ID!,
              $applicationIdentifier: String!
            ) {
              androidAppCredentials {
                createAndroidAppCredentials(
                  appId: $appId,
                  applicationIdentifier: $applicationIdentifier,
                  androidAppCredentialsInput: {}
                ) {
                  id
                }
              }
            }
            GRAPHQL, [
            'appId' => $project->external_id,
            'applicationIdentifier' => $identifier->identifier,
        ]);

        return $this->remoteId(
            (array) data_get(
                $data,
                'androidAppCredentials.createAndroidAppCredentials',
                [],
            ),
            'Android app credentials',
        );
    }

    /** @return array<string, mixed> */
    private function ensureAppleTeam(ExpoProject $project, string $teamIdentifier): array
    {
        $data = $this->expo->request($project->integration, <<<'GRAPHQL'
            query AppManagerAppleTeam($accountId: String!, $teamIdentifier: String!) {
              account {
                byId(accountId: $accountId) {
                  appleTeams(appleTeamIdentifier: $teamIdentifier, limit: 10, offset: 0) {
                    id
                    appleTeamIdentifier
                    appleTeamName
                  }
                }
              }
            }
            GRAPHQL, [
            'accountId' => $project->account_id,
            'teamIdentifier' => $teamIdentifier,
        ]);
        $team = collect((array) data_get($data, 'account.byId.appleTeams', []))->first();

        if (is_array($team)) {
            $this->remoteId($team, 'Apple team');

            return $team;
        }

        $created = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateAppleTeam($accountId: ID!, $input: AppleTeamInput!) {
              appleTeam {
                createAppleTeam(accountId: $accountId, appleTeamInput: $input) {
                  id
                  appleTeamIdentifier
                  appleTeamName
                }
              }
            }
            GRAPHQL, [
            'accountId' => $project->account_id,
            'input' => ['appleTeamIdentifier' => $teamIdentifier],
        ]);

        $team = (array) data_get($created, 'appleTeam.createAppleTeam', []);
        $this->remoteId($team, 'Apple team');

        return $team;
    }

    /** @return array<string, mixed> */
    private function ensureAppleAppIdentifier(
        ExpoProject $project,
        AppIdentifier $identifier,
        ?string $appleTeamId = null,
    ): array {
        $data = $this->expo->request($project->integration, <<<'GRAPHQL'
            query AppManagerAppleAppIdentifier($accountId: String!, $bundleIdentifier: String!) {
              account {
                byId(accountId: $accountId) {
                  appleAppIdentifiers(bundleIdentifier: $bundleIdentifier) {
                    id
                    bundleIdentifier
                  }
                }
              }
            }
            GRAPHQL, [
            'accountId' => $project->account_id,
            'bundleIdentifier' => $identifier->identifier,
        ]);
        $existing = collect((array) data_get($data, 'account.byId.appleAppIdentifiers', []))->first();

        if (is_array($existing)) {
            $this->remoteId($existing, 'Apple app identifier');

            return $existing;
        }

        $input = ['bundleIdentifier' => $identifier->identifier];
        if ($appleTeamId) {
            $input['appleTeamId'] = $appleTeamId;
        }
        $created = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateAppleAppIdentifier(
              $accountId: ID!,
              $input: AppleAppIdentifierInput!
            ) {
              appleAppIdentifier {
                createAppleAppIdentifier(
                  accountId: $accountId,
                  appleAppIdentifierInput: $input
                ) {
                  id
                  bundleIdentifier
                }
              }
            }
            GRAPHQL, [
            'accountId' => $project->account_id,
            'input' => $input,
        ]);

        $appleIdentifier = (array) data_get(
            $created,
            'appleAppIdentifier.createAppleAppIdentifier',
            [],
        );
        $this->remoteId($appleIdentifier, 'Apple app identifier');

        return $appleIdentifier;
    }

    private function ensureIosAppCredentials(
        ExpoProject $project,
        AppIdentifier $identifier,
        ?string $appleTeamId = null,
    ): string {
        $remote = $this->expo->project($project->integration, $project->external_id);
        $existing = collect((array) ($remote['iosAppCredentials'] ?? []))
            ->first(fn ($candidate) => data_get(
                $candidate,
                'appleAppIdentifier.bundleIdentifier',
            ) === $identifier->identifier);

        if (is_array($existing)) {
            return $this->remoteId($existing, 'iOS app credentials');
        }

        $appleIdentifier = $this->ensureAppleAppIdentifier($project, $identifier, $appleTeamId);
        $input = $appleTeamId ? ['appleTeamId' => $appleTeamId] : [];
        $data = $this->expo->request($project->integration, <<<'GRAPHQL'
            mutation AppManagerCreateIosAppCredentials(
              $appId: ID!,
              $appleAppIdentifierId: ID!,
              $input: IosAppCredentialsInput!
            ) {
              iosAppCredentials {
                createIosAppCredentials(
                  appId: $appId,
                  appleAppIdentifierId: $appleAppIdentifierId,
                  iosAppCredentialsInput: $input
                ) {
                  id
                }
              }
            }
            GRAPHQL, [
            'appId' => $project->external_id,
            'appleAppIdentifierId' => (string) $appleIdentifier['id'],
            'input' => (object) $input,
        ]);

        return $this->remoteId(
            (array) data_get($data, 'iosAppCredentials.createIosAppCredentials', []),
            'iOS app credentials',
        );
    }

    private function linkIosBuildCredentials(
        ExpoProject $project,
        AppIdentifier $identifier,
    ): void {
        $links = $project->credentialLinks()
            ->where('app_identifier_id', $identifier->id)
            ->whereIn('remote_resource_type', [
                'AppleDistributionCertificate',
                'AppleProvisioningProfile',
            ])
            ->latest()
            ->get();
        $certificates = $links->where('remote_resource_type', 'AppleDistributionCertificate');
        $profiles = $links->where('remote_resource_type', 'AppleProvisioningProfile');

        foreach ($profiles as $profile) {
            $profileType = data_get($profile->metadata, 'profile_type');
            $certificate = $certificates->first(fn ($candidate) => data_get(
                $candidate->metadata,
                'profile_type',
            ) === $profileType) ?? $certificates->first();
            if (! $certificate || data_get($profile->metadata, 'ios_app_build_credentials_id')) {
                continue;
            }

            $iosCredentialsId = $this->ensureIosAppCredentials($project, $identifier);
            $distributionType = match ($profileType) {
                ProfileType::Development->value => 'DEVELOPMENT',
                ProfileType::AdHoc->value => 'AD_HOC',
                ProfileType::Enterprise->value => 'ENTERPRISE',
                default => 'APP_STORE',
            };
            $data = $this->expo->request($project->integration, <<<'GRAPHQL'
                mutation AppManagerCreateIosBuildCredentials(
                  $iosAppCredentialsId: ID!,
                  $input: IosAppBuildCredentialsInput!
                ) {
                  iosAppBuildCredentials {
                    createIosAppBuildCredentials(
                      iosAppCredentialsId: $iosAppCredentialsId,
                      iosAppBuildCredentialsInput: $input
                    ) {
                      id
                    }
                  }
                }
                GRAPHQL, [
                'iosAppCredentialsId' => $iosCredentialsId,
                'input' => [
                    'distributionCertificateId' => $certificate->remote_resource_id,
                    'provisioningProfileId' => $profile->remote_resource_id,
                    'iosDistributionType' => $distributionType,
                ],
            ]);
            $buildCredentialsId = data_get(
                $data,
                'iosAppBuildCredentials.createIosAppBuildCredentials.id',
            );
            if (! is_string($buildCredentialsId) || trim($buildCredentialsId) === '') {
                throw new RuntimeException(
                    'Expo did not confirm the iOS certificate and provisioning profile attachment.',
                );
            }
            $profile->update([
                'metadata' => [
                    ...($profile->metadata ?? []),
                    'ios_app_credentials_id' => $iosCredentialsId,
                    'ios_app_build_credentials_id' => $buildCredentialsId,
                ],
            ]);
            $certificate->update([
                'metadata' => [
                    ...($certificate->metadata ?? []),
                    'ios_app_credentials_id' => $iosCredentialsId,
                    'ios_app_build_credentials_id' => $buildCredentialsId,
                ],
            ]);
        }
    }

    private function profileType(
        Credential $credential,
        AppIdentifier $identifier,
    ): ?ProfileType {
        return $credential->profiles()
            ->where('app_identifier_id', $identifier->id)
            ->first()
            ?->type;
    }

    private function fileContents(Credential $credential): string
    {
        $media = $credential->getFirstMedia('file');
        if (! $media) {
            throw new RuntimeException('Upload the credential file before pushing it to Expo.');
        }

        return CredentialFileStorage::contents($media);
    }

    /** @param array<string, mixed> $resource */
    private function remoteId(array $resource, string $label): string
    {
        $id = $resource['id'] ?? null;

        if (! is_string($id) || trim($id) === '') {
            throw new RuntimeException("Expo did not return an ID for {$label}.");
        }

        return $id;
    }
}
