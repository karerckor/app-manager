<?php

namespace Database\Seeders;

use App\Enums\ApiKeyScope;
use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\CredentialType;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Integrations\Notifications\NotificationEventCatalog;
use App\Models\Credential;
use App\Models\EncryptionKey;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\NotificationDestination;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\Credentials\CredentialFileStorage;
use App\Support\Encryption\EncryptionKeyManager;
use Illuminate\Database\Seeder;

/**
 * Seeds a ready-to-use demo account for local development:
 * demo@example.com / Demo123! with the demo-api-key API key, an Example App,
 * Android and iOS app identifiers, release build profiles, and signing
 * credentials for both platforms.
 *
 * Idempotent — safe to run repeatedly (php artisan db:seed).
 */
class DemoSeeder extends Seeder
{
    public const API_KEY = 'demo-api-key';

    private const KEYSTORE_ALIAS = 'production';

    private const KEYSTORE_PASSWORD = 'DemoKeystore123!';

    private const IOS_BUNDLE_IDENTIFIER = 'com.example.app';

    private const IOS_CERTIFICATE_PASSWORD = 'DemoCertificate123!';

    private const IOS_TEAM_ID = 'DEMO123456';

    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->command?->warn('DemoSeeder is disabled outside local, development, and testing environments.');

            return;
        }

        if (EncryptionKey::count() === 0) {
            EncryptionKeyManager::createKey();
        }

        // Demo user. The User model's 'hashed' cast hashes the password on set.
        $user = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => 'Demo123!'],
        );

        // The demo data needs a workspace: the demo user owns it and uses it as
        // their current team. Real users create this workspace during onboarding.
        $team = $user->currentTeam;

        if ($team === null) {
            $team = $user->teams()->create(
                ['name' => "Demo User's Team", 'owner_id' => $user->id],
                ['role' => 'owner'],
            );

            $user->update(['current_team_id' => $team->id]);
        }

        // Sanctum accepts tokens without the usual "id|" prefix and hashes
        // them before lookup. This keeps the demo key stable across seed runs
        // while storing only its SHA-256 hash in the database.
        $user->tokens()->updateOrCreate(
            ['name' => 'Demo API Key'],
            [
                'team_id' => $team->id,
                'token' => hash('sha256', self::API_KEY),
                'abilities' => ApiKeyScope::values(),
            ],
        );

        // Example App.
        $app = MobileApp::firstOrCreate(
            ['team_id' => $team->id, 'slug' => 'example-app'],
            ['name' => 'Example App', 'description' => 'Demo application for local development.'],
        );

        // Android app identifier: com.example.app.
        $identifier = $app->appIdentifiers()->firstOrCreate(
            ['platform' => Platform::Android, 'identifier' => 'com.example.app'],
            ['name' => 'Example App (Android)'],
        );

        // Production build profile on that identifier.
        $profile = $identifier->profiles()->firstOrCreate(
            ['type' => ProfileType::Production],
            ['name' => 'Production'],
        );

        // Production keystore credential (team-owned). Secrets live in the
        // encrypted payload; the keystore file lives in the media 'file' collection.
        $keystore = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::AndroidKeystore, 'name' => 'Production Keystore'],
            ['payload' => [
                'store_password' => self::KEYSTORE_PASSWORD,
                'key_alias' => self::KEYSTORE_ALIAS,
                // PKCS#12 uses one password for both the store and private key.
                'key_password' => self::KEYSTORE_PASSWORD,
            ]],
        );

        // Assign signing material to the production build target. Detaching the
        // old direct relation also upgrades databases seeded before build targets.
        $identifier->credentials()->detach($keystore->id);
        $profile->credentials()->syncWithoutDetaching([$keystore->id]);

        // Replace the old placeholder once with a real PKCS#12 keystore. The
        // friendly name is the Android key alias stored in the payload above.
        $media = $keystore->getFirstMedia('file');

        if (! $media || ! CredentialFileStorage::withTemporaryPlaintext(
            $media,
            fn (string $path): bool => $this->isValidDemoKeystore($path),
        )) {
            CredentialFileStorage::storeContents($keystore, $this->makeDemoKeystore(), 'production.keystore');
        }

        // iOS App Store target and its two required signing credentials.
        $iosIdentifier = $app->appIdentifiers()->firstOrCreate(
            ['platform' => Platform::Ios, 'identifier' => self::IOS_BUNDLE_IDENTIFIER],
            ['name' => 'Example App (iOS)'],
        );
        $iosBuildProfile = $iosIdentifier->profiles()->firstOrCreate(
            ['type' => ProfileType::AppStore],
            ['name' => 'App Store'],
        );
        $iosCertificate = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::IosCertificate, 'name' => 'App Store Certificate'],
            ['payload' => ['password' => self::IOS_CERTIFICATE_PASSWORD]],
        );
        $provisioningProfile = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::ProvisioningProfile, 'name' => 'App Store Provisioning Profile'],
            ['payload' => null],
        );

        $iosIdentifier->credentials()->detach([$iosCertificate->id, $provisioningProfile->id]);
        $iosBuildProfile->credentials()->syncWithoutDetaching([
            $iosCertificate->id,
            $provisioningProfile->id,
        ]);

        $certificateMedia = $iosCertificate->getFirstMedia('file');
        $profileMedia = $provisioningProfile->getFirstMedia('file');

        if (
            ! $certificateMedia
            || ! CredentialFileStorage::withTemporaryPlaintext(
                $certificateMedia,
                fn (string $path): bool => $this->isValidIosCertificate($path),
            )
            || ! $profileMedia
            || ! CredentialFileStorage::withTemporaryPlaintext(
                $profileMedia,
                fn (string $path): bool => $this->isValidDemoProvisioningProfile($path),
            )
        ) {
            $materials = $this->makeIosSigningMaterials();
            CredentialFileStorage::storeContents($iosCertificate, $materials['certificate'], 'distribution.p12');
            CredentialFileStorage::storeContents($provisioningProfile, $materials['provisioning_profile'], 'appstore.mobileprovision');
        }

        if (! $app->hasMedia('app_icon')) {
            $app->addMediaFromString($this->makePng(1024, '#3b82f6'))
                ->usingFileName('icon.png')
                ->toMediaCollection('app_icon');
        }

        if (! $app->hasMedia('splash_icon')) {
            $app->addMediaFromString($this->makePng(2048, '#0f172a'))
                ->usingFileName('splash.png')
                ->toMediaCollection('splash_icon');
        }

        // Google Play service account (store submission) on the Android identifier.
        $googlePlay = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::GooglePlayServiceAccount, 'name' => 'Google Play Service Account'],
            ['payload' => null],
        );
        $identifier->credentials()->syncWithoutDetaching([$googlePlay->id]);

        if (! $googlePlay->hasMedia('file')) {
            CredentialFileStorage::storeContents($googlePlay, $this->makeDemoServiceAccount(), 'google-play-service-account.json', 'application/json');
        }

        // Firebase Cloud Messaging HTTP v1 service account on the Android identifier.
        $firebaseHttpV1 = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::FirebaseConfig, 'name' => 'Firebase HTTP v1 Service Account'],
            ['payload' => null],
        );
        $identifier->credentials()->syncWithoutDetaching([$firebaseHttpV1->id]);

        if (! $firebaseHttpV1->hasMedia('file')) {
            CredentialFileStorage::storeContents($firebaseHttpV1, $this->makeDemoServiceAccount(), 'firebase-http-v1-service-account.json', 'application/json');
        }

        // App Store Connect API key (TestFlight submission) on the iOS identifier.
        $appleKey = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::AppleApiKey, 'name' => 'App Store Connect API Key'],
            ['payload' => ['key_id' => 'DEMOKEY123', 'issuer_id' => '11111111-1111-4111-8111-111111111111']],
        );
        $iosIdentifier->credentials()->syncWithoutDetaching([$appleKey->id]);

        if (! $appleKey->hasMedia('file')) {
            openssl_pkey_export(openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]), $p8);
            CredentialFileStorage::storeContents($appleKey, $p8, 'appstore-api-key.p8', 'application/x-pem-file');
        }

        // Client Firebase config for Android.
        $androidFirebase = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::GoogleServicesJson, 'name' => 'Firebase google-services.json'],
            ['payload' => null],
        );
        $identifier->credentials()->syncWithoutDetaching([$androidFirebase->id]);

        if (! $androidFirebase->hasMedia('file')) {
            CredentialFileStorage::storeContents($androidFirebase, $this->makeGoogleServicesJson(), 'google-services.json', 'application/json');
        }

        // Client Firebase config for iOS.
        $iosFirebase = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::GoogleServiceInfoPlist, 'name' => 'Firebase GoogleService-Info.plist'],
            ['payload' => null],
        );
        $iosIdentifier->credentials()->syncWithoutDetaching([$iosFirebase->id]);

        if (! $iosFirebase->hasMedia('file')) {
            CredentialFileStorage::storeContents($iosFirebase, $this->makeGoogleServiceInfoPlist(), 'GoogleService-Info.plist', 'application/xml');
        }

        // Web identifier: no signing credentials, just a deploy target.
        $webIdentifier = $app->appIdentifiers()->firstOrCreate(
            ['platform' => Platform::Web, 'identifier' => 'com.example.app.web'],
            ['name' => 'Example App (Web)'],
        );
        $webBuildProfile = $webIdentifier->profiles()->firstOrCreate(
            ['type' => ProfileType::Production],
            ['name' => 'Production'],
        );

        // App metadata: free-form JSON that flows into the mobile app at build
        // time via the prepare CLI. Demonstrates feature flags, environment
        // config, and minimum supported version management from Platform.
        $app->update(['metadata' => [
            'environment' => 'development',
            'features' => [
                'push_notifications' => true,
                'analytics' => false,
                'dark_mode' => true,
            ],
            'min_supported_version' => '1.0.0',
            'remote_config' => [
                'api_timeout_ms' => 5000,
                'max_retries' => 3,
            ],
        ]]);

        // Build history: demonstrates the builds API and shows past builds in
        // the Platform UI. The completed build has a full log; the pending
        // build simulates a job waiting for a CI runner.
        $app->buildRecords()->firstOrCreate(
            ['request_uuid' => '00000000-0000-4000-8000-000000000001'],
            [
                'team_id' => $team->id,
                'app_identifier_id' => $identifier->id,
                'requested_by_user_id' => $user->id,
                'profile_type' => ProfileType::Production,
                'status' => BuildStatus::Completed,
                'reporting_mode' => BuildReportingMode::Manual,
                'context_snapshot' => ['runner' => 'github-actions', 'commit' => 'abc1234'],
                'parameters' => ['channel' => 'internal', 'minify' => true],
                'log_output' => implode("\n", [
                    '[1/4] Compiling sources...',
                    '[2/4] Bundling assets...',
                    '[3/4] Signing AAB with production keystore...',
                    '[4/4] Build completed successfully.',
                ]),
                'started_at' => now()->subHours(2),
                'finished_at' => now()->subHours(2)->addMinutes(3),
                'tags' => [['demo', null], ['android', null]],
            ],
        );

        $app->buildRecords()->firstOrCreate(
            ['request_uuid' => '00000000-0000-4000-8000-000000000002'],
            [
                'team_id' => $team->id,
                'app_identifier_id' => $iosIdentifier->id,
                'requested_by_user_id' => $user->id,
                'profile_type' => ProfileType::AppStore,
                'status' => BuildStatus::Pending,
                'reporting_mode' => BuildReportingMode::Manual,
                'context_snapshot' => ['runner' => 'self-hosted'],
                'parameters' => ['channel' => 'beta'],
                'tags' => [['demo', null], ['ios', null]],
            ],
        );

        // Web builds: one completed deploy, one failed build.
        $app->buildRecords()->firstOrCreate(
            ['request_uuid' => '00000000-0000-4000-8000-000000000003'],
            [
                'team_id' => $team->id,
                'app_identifier_id' => $webIdentifier->id,
                'requested_by_user_id' => $user->id,
                'profile_type' => ProfileType::Production,
                'status' => BuildStatus::Completed,
                'reporting_mode' => BuildReportingMode::Manual,
                'context_snapshot' => ['runner' => 'vercel', 'commit' => 'def5678'],
                'parameters' => ['channel' => 'production'],
                'log_output' => implode("\n", [
                    '[1/5] Installing dependencies...',
                    '[2/5] Compiling TypeScript...',
                    '[3/5] Bundling with Vite...',
                    '[4/5] Uploading to CDN...',
                    '[5/5] Deploy completed successfully.',
                ]),
                'started_at' => now()->subHour(),
                'finished_at' => now()->subHour()->addMinutes(1),
                'tags' => [['demo', null], ['web', null]],
            ],
        );

        $app->buildRecords()->firstOrCreate(
            ['request_uuid' => '00000000-0000-4000-8000-000000000004'],
            [
                'team_id' => $team->id,
                'app_identifier_id' => $webIdentifier->id,
                'requested_by_user_id' => $user->id,
                'profile_type' => ProfileType::Production,
                'status' => BuildStatus::Failed,
                'reporting_mode' => BuildReportingMode::Manual,
                'context_snapshot' => ['runner' => 'github-actions', 'commit' => 'bad0001'],
                'parameters' => ['channel' => 'staging'],
                'log_output' => implode("\n", [
                    '[1/4] Installing dependencies...',
                    '[2/4] Compiling TypeScript...',
                    '[3/4] Bundling with Vite...',
                    'ERROR: Module not found: "./missing-module"',
                    'Build failed with 1 error.',
                ]),
                'started_at' => now()->subMinutes(30),
                'finished_at' => now()->subMinutes(28),
                'tags' => [['demo', null], ['web', null]],
            ],
        );

        // Webhook endpoint: demonstrates how Platform notifies external
        // systems about build lifecycle events.
        $webhookIntegration = Integration::firstOrCreate(
            [
                'team_id' => $team->id,
                'kind' => IntegrationKind::Webhook,
                'provider' => IntegrationProvider::Http,
                'name' => 'Demo Webhook',
            ],
            [
                'status' => IntegrationStatus::Active,
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ],
        );

        $webhookEndpoint = WebhookEndpoint::firstOrCreate(
            ['integration_id' => $webhookIntegration->id],
            [
                'team_id' => $team->id,
                'url' => 'https://example.com/app-manager-webhooks',
                'secret' => 'whsec_demo123456789abcdef',
                'secret_hint' => 'demo1…ef',
                'subscribed_events' => ['build.completed', 'artifact.created'],
                'filters' => ['build_statuses' => ['completed', 'failed']],
            ],
        );

        if ($webhookIntegration->external_id !== $webhookEndpoint->id) {
            $webhookIntegration->update(['external_id' => $webhookEndpoint->id]);
        }

        // Notification destination: templates and subscriptions are ready, while
        // the operator still needs to add a real provider credential.
        $notificationIntegration = Integration::firstOrCreate(
            [
                'team_id' => $team->id,
                'kind' => IntegrationKind::Notification,
                'name' => 'Release room',
            ],
            [
                'provider' => IntegrationProvider::Slack,
                'status' => IntegrationStatus::Disabled,
                'disabled_at' => now(),
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
            ],
        );

        if ($notificationIntegration->provider === IntegrationProvider::Custom) {
            $notificationIntegration->update([
                'provider' => IntegrationProvider::Slack,
                'status' => IntegrationStatus::Disabled,
                'disabled_at' => now(),
            ]);
        }

        $notificationDestination = NotificationDestination::updateOrCreate(
            ['integration_id' => $notificationIntegration->id],
            [
                'team_id' => $team->id,
                'destination' => '#mobile-releases',
                'subscribed_events' => ['build.started', 'build.completed', 'artifact.created'],
                'templates' => NotificationEventCatalog::defaultTemplates(),
            ],
        );

        if ($notificationIntegration->external_id !== $notificationDestination->id) {
            $notificationIntegration->update(['external_id' => $notificationDestination->id]);
        }

        // Project files: arbitrary files attached to the app, downloadable
        // via the files API. Demonstrates that Platform can store and serve
        // not just credentials, but any project-related files.
        if ($app->getMedia('files')->isEmpty()) {
            $app->addMediaFromString($this->makeReleaseNotes())
                ->usingFileName('release-notes.md')
                ->withCustomProperties(['metadata' => ['version' => '1.0.0', 'channel' => 'stable']])
                ->toMediaCollection('files');

            $app->addMediaFromString($this->makeAppConfig())
                ->usingFileName('app-config.json')
                ->withCustomProperties(['metadata' => ['environment' => 'development']])
                ->toMediaCollection('files');
        }
    }

    private function makePng(int $size, string $hex): string
    {
        $image = imagecreatetruecolor($size, $size);

        if ($image === false) {
            throw new \RuntimeException('Unable to create the demo image.');
        }

        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }

    private function makeDemoServiceAccount(): string
    {
        openssl_pkey_export(openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]), $privateKey);

        return json_encode([
            'type' => 'service_account',
            'project_id' => 'app-manager-demo',
            'private_key_id' => 'demo-key-id',
            'private_key' => $privateKey,
            'client_email' => 'demo@app-manager-demo.iam.gserviceaccount.com',
            'client_id' => '000000000000000000000',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    private function makeGoogleServicesJson(): string
    {
        return json_encode([
            'project_info' => [
                'project_number' => '000000000000',
                'project_id' => 'app-manager-demo',
                'storage_bucket' => 'app-manager-demo.appspot.com',
            ],
            'client' => [[
                'client_info' => [
                    'mobilesdk_app_id' => '1:000000000000:android:0000000000000000',
                    'android_client_info' => ['package_name' => self::IOS_BUNDLE_IDENTIFIER],
                ],
                'api_key' => [['current_key' => 'DEMO-ANDROID-API-KEY']],
            ]],
            'configuration_version' => '1',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    private function makeGoogleServiceInfoPlist(): string
    {
        $bundleId = self::IOS_BUNDLE_IDENTIFIER;

        return <<<PLIST
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
            <key>BUNDLE_ID</key><string>{$bundleId}</string>
            <key>PROJECT_ID</key><string>app-manager-demo</string>
            <key>GOOGLE_APP_ID</key><string>1:000000000000:ios:0000000000000000</string>
            <key>API_KEY</key><string>DEMO-IOS-API-KEY</string>
            <key>GCM_SENDER_ID</key><string>000000000000</string>
            <key>IS_ADS_ENABLED</key><false/>
        </dict>
        </plist>
        PLIST;
    }

    private function isValidDemoKeystore(string $path): bool
    {
        $contents = @file_get_contents($path);
        $certificates = [];

        return is_string($contents)
            && @openssl_pkcs12_read($contents, $certificates, self::KEYSTORE_PASSWORD)
            && isset($certificates['pkey'], $certificates['cert']);
    }

    private function makeDemoKeystore(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new \RuntimeException('Unable to generate the demo keystore private key.');
        }

        $subject = [
            'commonName' => 'App Manager Demo',
            'organizationName' => 'App Manager',
            'organizationalUnitName' => 'Development',
            'countryName' => 'ES',
        ];
        $request = openssl_csr_new($subject, $key, ['digest_alg' => 'sha256']);

        if ($request === false) {
            throw new \RuntimeException('Unable to generate the demo keystore certificate request.');
        }

        $certificate = openssl_csr_sign($request, null, $key, 3650, ['digest_alg' => 'sha256']);

        if ($certificate === false) {
            throw new \RuntimeException('Unable to sign the demo keystore certificate.');
        }

        $exported = openssl_pkcs12_export(
            $certificate,
            $contents,
            $key,
            self::KEYSTORE_PASSWORD,
            ['friendly_name' => self::KEYSTORE_ALIAS],
        );

        if (! $exported) {
            throw new \RuntimeException('Unable to export the demo PKCS#12 keystore.');
        }

        return $contents;
    }

    private function isValidIosCertificate(string $path): bool
    {
        $contents = @file_get_contents($path);
        $certificates = [];

        return is_string($contents)
            && @openssl_pkcs12_read($contents, $certificates, self::IOS_CERTIFICATE_PASSWORD)
            && isset($certificates['pkey'], $certificates['cert']);
    }

    private function isValidDemoProvisioningProfile(string $path): bool
    {
        $decodedPath = tempnam(sys_get_temp_dir(), 'app-manager-profile-');

        if ($decodedPath === false) {
            return false;
        }

        try {
            $verified = @openssl_cms_verify(
                $path,
                OPENSSL_CMS_NOVERIFY,
                null,
                [],
                null,
                $decodedPath,
                null,
                null,
                OPENSSL_ENCODING_DER,
            );
            $contents = $verified ? @file_get_contents($decodedPath) : false;

            return is_string($contents)
                && str_contains(
                    $contents,
                    '<string>'.self::IOS_TEAM_ID.'.'.self::IOS_BUNDLE_IDENTIFIER.'</string>',
                )
                && str_contains($contents, '<string>'.self::IOS_TEAM_ID.'</string>');
        } finally {
            @unlink($decodedPath);
        }
    }

    private function makeIosSigningMaterials(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new \RuntimeException('Unable to generate the demo iOS certificate private key.');
        }

        $subject = [
            'commonName' => 'Apple Distribution: App Manager Demo ('.self::IOS_TEAM_ID.')',
            'organizationName' => 'App Manager',
            'organizationalUnitName' => self::IOS_TEAM_ID,
            'countryName' => 'ES',
        ];
        $request = openssl_csr_new($subject, $key, ['digest_alg' => 'sha256']);

        if ($request === false) {
            throw new \RuntimeException('Unable to generate the demo iOS certificate request.');
        }

        $certificate = openssl_csr_sign($request, null, $key, 3650, ['digest_alg' => 'sha256']);

        if ($certificate === false) {
            throw new \RuntimeException('Unable to sign the demo iOS certificate.');
        }

        $exported = openssl_pkcs12_export(
            $certificate,
            $certificateContents,
            $key,
            self::IOS_CERTIFICATE_PASSWORD,
            ['friendly_name' => 'distribution'],
        );

        if (! $exported) {
            throw new \RuntimeException('Unable to export the demo iOS PKCS#12 certificate.');
        }

        if (! openssl_x509_export($certificate, $certificatePem)) {
            throw new \RuntimeException('Unable to export the demo iOS public certificate.');
        }

        $certificateDer = base64_decode(
            preg_replace('/-----[^-]+-----|\s+/', '', $certificatePem),
            true,
        );

        if ($certificateDer === false) {
            throw new \RuntimeException('Unable to encode the demo iOS public certificate.');
        }

        return [
            'certificate' => $certificateContents,
            'provisioning_profile' => $this->makeDemoProvisioningProfile(
                $certificate,
                $key,
                $certificateDer,
            ),
        ];
    }

    private function makeDemoProvisioningProfile(
        \OpenSSLCertificate $certificate,
        \OpenSSLAsymmetricKey $key,
        string $certificateDer,
    ): string {
        $createdAt = now()->utc();
        $expiresAt = $createdAt->copy()->addYears(10);
        $encodedCertificate = base64_encode($certificateDer);
        $bundleIdentifier = self::IOS_BUNDLE_IDENTIFIER;
        $teamId = self::IOS_TEAM_ID;
        $profile = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>AppIDName</key>
    <string>Example App</string>
    <key>ApplicationIdentifierPrefix</key>
    <array><string>{$teamId}</string></array>
    <key>CreationDate</key>
    <date>{$createdAt->format('Y-m-d\TH:i:s\Z')}</date>
    <key>DeveloperCertificates</key>
    <array><data>{$encodedCertificate}</data></array>
    <key>Entitlements</key>
    <dict>
        <key>application-identifier</key>
        <string>{$teamId}.{$bundleIdentifier}</string>
        <key>com.apple.developer.team-identifier</key>
        <string>{$teamId}</string>
        <key>get-task-allow</key>
        <false/>
    </dict>
    <key>ExpirationDate</key>
    <date>{$expiresAt->format('Y-m-d\TH:i:s\Z')}</date>
    <key>Name</key>
    <string>Example App Demo App Store</string>
    <key>Platform</key>
    <array><string>iOS</string></array>
    <key>TeamIdentifier</key>
    <array><string>{$teamId}</string></array>
    <key>TeamName</key>
    <string>App Manager Demo</string>
    <key>TimeToLive</key>
    <integer>3650</integer>
    <key>UUID</key>
    <string>00000000-0000-4000-8000-000000000001</string>
    <key>Version</key>
    <integer>1</integer>
</dict>
</plist>
XML;
        $inputPath = tempnam(sys_get_temp_dir(), 'app-manager-profile-input-');
        $outputPath = tempnam(sys_get_temp_dir(), 'app-manager-profile-output-');

        if ($inputPath === false || $outputPath === false) {
            throw new \RuntimeException('Unable to create temporary files for the demo provisioning profile.');
        }

        try {
            if (file_put_contents($inputPath, $profile) === false) {
                throw new \RuntimeException('Unable to write the demo provisioning profile payload.');
            }

            $signed = openssl_cms_sign(
                $inputPath,
                $outputPath,
                $certificate,
                $key,
                [],
                OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_DER,
            );
            $contents = $signed ? file_get_contents($outputPath) : false;

            if (! is_string($contents) || $contents === '') {
                throw new \RuntimeException('Unable to sign the demo provisioning profile.');
            }

            return $contents;
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    private function makeReleaseNotes(): string
    {
        return <<<'MARKDOWN'
# Example App v1.0.0

## Initial release

- Hello world screen with Platform integration demo
- Automatic Expo config generation from App Manager
- Android and iOS signing credentials from Platform
- Push notification support (feature flag controlled)
- Dark mode support (feature flag controlled)

## Build info

- Android: AAB signed with production keystore
- iOS: IPA signed with App Store certificate
MARKDOWN;
    }

    private function makeAppConfig(): string
    {
        return json_encode([
            'app' => [
                'name' => 'Example App',
                'version' => '1.0.0',
                'build' => '1',
            ],
            'api' => [
                'baseUrl' => 'https://api.example.com',
                'timeout' => 5000,
                'retries' => 3,
            ],
            'features' => [
                'push_notifications' => true,
                'analytics' => false,
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
