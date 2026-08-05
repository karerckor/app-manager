<?php

namespace Database\Seeders;

use App\Enums\BuildStatus;
use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Builds\BuildContextResolver;
use App\Support\Builds\BuildSnapshotFactory;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;

/**
 * Seeds a ready-to-use demo account for local development:
 * demo@example.com / Demo123! with the demo-api-key API key, an Example App,
 * Android and iOS app identifiers, release build profiles, and signing
 * credentials for both platforms, plus a ready-to-browse CI build history.
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

    private const BLUE_ANDROID_IDENTIFIER = 'com.example.blue';

    private const BLUE_IOS_BUNDLE_IDENTIFIER = 'com.example.blue';

    private const BLUE_KEYSTORE_ALIAS = 'blue-production';

    private const BLUE_KEYSTORE_PASSWORD = 'BlueKeystore123!';

    private const BLUE_IOS_CERTIFICATE_PASSWORD = 'BlueCertificate123!';

    public function run(
        BuildContextResolver $resolver,
        BuildSnapshotFactory $snapshots,
    ): void {
        // Demo user. The User model's 'hashed' cast hashes the password on set.
        $user = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => 'Demo123!'],
        );

        // Personal team — mirrors registration (App\Actions\Fortify\CreateNewUser):
        // user is the owner, pivot role 'owner', and it becomes the current team.
        $team = $user->currentTeam;

        if ($team === null) {
            $team = Team::create([
                'name' => "Demo User's Team",
                'owner_id' => $user->id,
            ]);
            $team->addUser($user, 'owner');

            $user->update(['current_team_id' => $team->id]);
        }

        // Sanctum accepts tokens without the usual "id|" prefix and hashes
        // them before lookup. This keeps the demo key stable across seed runs
        // while storing only its SHA-256 hash in the database.
        $user->tokens()->updateOrCreate(
            ['name' => 'Demo API Key'],
            ['token' => hash('sha256', self::API_KEY), 'abilities' => ['*']],
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
        $identifier->update([
            'name' => 'Example App (Android)',
            'version' => '1.0.0',
            'version_code' => 1,
            'is_default' => true,
        ]);

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

        if (! $media || ! $this->isValidDemoKeystore($media->getPath())) {
            $keystore->addMediaFromString($this->makeDemoKeystore())
                ->usingFileName('production.keystore')
                ->toMediaCollection('file');
        }

        // A non-default target proves explicit identifier/profile selection.
        $betaIdentifier = $app->appIdentifiers()->firstOrCreate(
            ['platform' => Platform::Android, 'identifier' => 'com.example.app.beta'],
            ['name' => 'Example App Beta (Android)'],
        );
        $betaIdentifier->update([
            'name' => 'Example App Beta (Android)',
            'version' => '1.1.0-beta.1',
            'version_code' => 10100,
            'is_default' => false,
        ]);
        $betaProfile = $betaIdentifier->profiles()->firstOrCreate(
            ['type' => ProfileType::Preview],
            ['name' => 'Preview'],
        );
        $betaKeystore = Credential::updateOrCreate(
            [
                'team_id' => $team->id,
                'type' => CredentialType::AndroidKeystore,
                'name' => 'Beta Preview Keystore',
            ],
            ['payload' => [
                'store_password' => self::KEYSTORE_PASSWORD,
                'key_alias' => 'beta-preview',
                'key_password' => self::KEYSTORE_PASSWORD,
            ]],
        );
        $betaProfile->credentials()->syncWithoutDetaching([$betaKeystore->id]);

        if (! $betaKeystore->hasMedia('file')) {
            $betaKeystore->addMediaFromString($this->makeDemoKeystore(
                self::KEYSTORE_PASSWORD,
                'beta-preview',
                'App Manager Demo Beta',
            ))
                ->usingFileName('beta-preview.keystore')
                ->toMediaCollection('file');
        }

        // iOS App Store target and its two required signing credentials.
        $iosIdentifier = $app->appIdentifiers()->firstOrCreate(
            ['platform' => Platform::Ios, 'identifier' => self::IOS_BUNDLE_IDENTIFIER],
            ['name' => 'Example App (iOS)'],
        );
        $iosIdentifier->update([
            'name' => 'Example App (iOS)',
            'version' => '1.0.0',
            'build_number' => '1',
            'is_default' => true,
        ]);
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
            || ! $this->isValidIosCertificate($certificateMedia->getPath())
            || ! $profileMedia
            || ! $this->isValidDemoProvisioningProfile($profileMedia->getPath())
        ) {
            $materials = $this->makeIosSigningMaterials();
            $iosCertificate->addMediaFromString($materials['certificate'])
                ->usingFileName('distribution.p12')
                ->toMediaCollection('file');
            $provisioningProfile->addMediaFromString($materials['provisioning_profile'])
                ->usingFileName('appstore.mobileprovision')
                ->toMediaCollection('file');
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
            $googlePlay->addMediaFromString($this->makeDemoServiceAccount())
                ->usingFileName('google-play-service-account.json')
                ->toMediaCollection('file');
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
            $appleKey->addMediaFromString($p8)
                ->usingFileName('appstore-api-key.p8')
                ->toMediaCollection('file');
        }

        // Client Firebase config for Android.
        $androidFirebase = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::GoogleServicesJson, 'name' => 'Firebase google-services.json'],
            ['payload' => null],
        );
        $identifier->credentials()->syncWithoutDetaching([$androidFirebase->id]);

        if (! $androidFirebase->hasMedia('file')) {
            $androidFirebase->addMediaFromString($this->makeGoogleServicesJson())
                ->usingFileName('google-services.json')
                ->toMediaCollection('file');
        }

        // Client Firebase config for iOS.
        $iosFirebase = Credential::updateOrCreate(
            ['team_id' => $team->id, 'type' => CredentialType::GoogleServiceInfoPlist, 'name' => 'Firebase GoogleService-Info.plist'],
            ['payload' => null],
        );
        $iosIdentifier->credentials()->syncWithoutDetaching([$iosFirebase->id]);

        if (! $iosFirebase->hasMedia('file')) {
            $iosFirebase->addMediaFromString($this->makeGoogleServiceInfoPlist())
                ->usingFileName('GoogleService-Info.plist')
                ->toMediaCollection('file');
        }

        $this->seedBlueBrand($team);
        $this->seedBuildRecords($team, $app, $resolver, $snapshots);
    }

    private function seedBuildRecords(
        Team $team,
        MobileApp $app,
        BuildContextResolver $resolver,
        BuildSnapshotFactory $snapshots,
    ): void {
        $completedAt = now()->subDays(3)->setTime(10, 15);
        $failedAt = now()->subDay()->setTime(16, 40);

        $completed = $this->upsertBuildRecord(
            $team,
            $app,
            $resolver,
            $snapshots,
            Platform::Android,
            ProfileType::Production,
            'com.example.app',
            BuildStatus::Completed,
            '1001',
            '8d87d71c06b18b09e221f0cb06807e47ec44cfa8',
            $completedAt,
            $completedAt->copy()->addMinutes(8),
            'https://artifacts.example.test/example-app-1.0.0.aab',
        );
        $failed = $this->upsertBuildRecord(
            $team,
            $app,
            $resolver,
            $snapshots,
            Platform::Ios,
            ProfileType::AppStore,
            self::IOS_BUNDLE_IDENTIFIER,
            BuildStatus::Failed,
            '1002',
            'af14c32ecfea0e17f98f795fab1e19e37944c106',
            $failedAt,
            $failedAt->copy()->addMinutes(5),
        );
        $running = $this->upsertBuildRecord(
            $team,
            $app,
            $resolver,
            $snapshots,
            Platform::Android,
            ProfileType::Preview,
            'com.example.app.beta',
            BuildStatus::Running,
            '1003',
            'c61b37503d0cd21f61e930ab0eb2d30a056c2c0d',
            now()->subMinutes(12),
        );
        $pending = $this->upsertBuildRecord(
            $team,
            $app,
            $resolver,
            $snapshots,
            Platform::Ios,
            ProfileType::AppStore,
            self::IOS_BUNDLE_IDENTIFIER,
            BuildStatus::Pending,
            '1004',
            '671829b95dbb42fce71d8d7cbe90f6a56d498d45',
            null,
        );

        $this->seedBuildLogs($completed, [
            ['stream' => 'system', 'level' => 'info', 'message' => 'Build claimed by GitHub Actions'],
            ['stream' => 'stdout', 'level' => 'info', 'message' => 'Assembling Android production bundle'],
            ['stream' => 'stdout', 'level' => 'info', 'message' => 'Artifact uploaded: example-app-1.0.0.aab'],
        ]);
        $this->seedBuildLogs($failed, [
            ['stream' => 'system', 'level' => 'info', 'message' => 'Build claimed by GitHub Actions'],
            ['stream' => 'stderr', 'level' => 'error', 'message' => 'Archive step failed with exit status 65'],
        ]);
        $this->seedBuildLogs($running, [
            ['stream' => 'system', 'level' => 'info', 'message' => 'Build claimed by GitHub Actions'],
            ['stream' => 'stdout', 'level' => 'info', 'message' => 'Compiling beta preview bundle…'],
        ]);
        $this->seedBuildLogs($pending, [
            ['stream' => 'system', 'level' => 'info', 'message' => 'Build registered; waiting for a runner'],
        ]);
    }

    private function upsertBuildRecord(
        Team $team,
        MobileApp $app,
        BuildContextResolver $resolver,
        BuildSnapshotFactory $snapshots,
        Platform $platform,
        ProfileType $profileType,
        string $identifier,
        BuildStatus $status,
        string $runId,
        string $commitSha,
        ?CarbonInterface $startedAt,
        ?CarbonInterface $finishedAt = null,
        ?string $artifactUrl = null,
    ): BuildRecord {
        $context = $resolver->resolve($app, $platform, $profileType, $identifier);
        $externalId = "demo-build-{$runId}";
        $record = $team->buildRecords()
            ->where(function ($query) use ($externalId, $runId, $status) {
                $query->where('external_id', $externalId);

                if ($status !== BuildStatus::Pending) {
                    $query->orWhere(fn ($query) => $query
                        ->where('ci_provider', 'github')
                        ->where('ci_run_id', $runId));
                }
            })
            ->first() ?? new BuildRecord(['team_id' => $team->id]);
        $record->fill([
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $context['target']->id,
            'external_id' => $externalId,
            'profile_type' => $profileType,
            'status' => $status,
            'commit_sha' => $commitSha,
            'ci_provider' => $status === BuildStatus::Pending ? null : 'github',
            'ci_run_id' => $status === BuildStatus::Pending ? null : $runId,
            'ci_run_url' => $status === BuildStatus::Pending
                ? null
                : "https://github.com/example/example-app/actions/runs/{$runId}",
            'artifact_url' => $artifactUrl,
            'context_schema_version' => BuildSnapshotFactory::SCHEMA_VERSION,
            'context_snapshot' => $snapshots->make($app, $context),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ])->save();

        return $record;
    }

    private function seedBuildLogs(BuildRecord $record, array $entries): void
    {
        foreach ($entries as $index => $entry) {
            $record->logs()->updateOrCreate(
                ['sequence' => $index + 1],
                [
                    ...$entry,
                    'logged_at' => $record->started_at ?? $record->created_at,
                ],
            );
        }
    }

    private function seedBlueBrand(Team $team): void
    {
        $app = MobileApp::firstOrCreate(
            ['team_id' => $team->id, 'slug' => 'example-app-blue'],
            ['name' => 'Blue Example App'],
        );
        $app->update([
            'name' => 'Blue Example App',
            'description' => 'Second white-label application built from the same demo source.',
        ]);

        $android = $app->appIdentifiers()->firstOrCreate(
            ['platform' => Platform::Android, 'identifier' => self::BLUE_ANDROID_IDENTIFIER],
            ['name' => 'Blue Example App (Android)'],
        );
        $android->update([
            'name' => 'Blue Example App (Android)',
            'version' => '2.0.0',
            'version_code' => 20000,
            'is_default' => true,
        ]);
        $androidProfile = $android->profiles()->firstOrCreate(
            ['type' => ProfileType::Production],
            ['name' => 'Production'],
        );
        $keystore = Credential::updateOrCreate(
            [
                'team_id' => $team->id,
                'type' => CredentialType::AndroidKeystore,
                'name' => 'Blue Production Keystore',
            ],
            ['payload' => [
                'store_password' => self::BLUE_KEYSTORE_PASSWORD,
                'key_alias' => self::BLUE_KEYSTORE_ALIAS,
                'key_password' => self::BLUE_KEYSTORE_PASSWORD,
            ]],
        );
        $android->credentials()->detach($keystore->id);
        $androidProfile->credentials()->syncWithoutDetaching([$keystore->id]);
        $keystoreMedia = $keystore->getFirstMedia('file');

        if (
            ! $keystoreMedia
            || ! $this->isValidDemoKeystore($keystoreMedia->getPath(), self::BLUE_KEYSTORE_PASSWORD)
        ) {
            $keystore->addMediaFromString($this->makeDemoKeystore(
                self::BLUE_KEYSTORE_PASSWORD,
                self::BLUE_KEYSTORE_ALIAS,
                'App Manager Blue Demo',
            ))
                ->usingFileName('blue-production.keystore')
                ->toMediaCollection('file');
        }

        $ios = $app->appIdentifiers()->firstOrCreate(
            ['platform' => Platform::Ios, 'identifier' => self::BLUE_IOS_BUNDLE_IDENTIFIER],
            ['name' => 'Blue Example App (iOS)'],
        );
        $ios->update([
            'name' => 'Blue Example App (iOS)',
            'version' => '2.0.0',
            'build_number' => '20000',
            'is_default' => true,
        ]);
        $iosProfile = $ios->profiles()->firstOrCreate(
            ['type' => ProfileType::AppStore],
            ['name' => 'App Store'],
        );
        $certificate = Credential::updateOrCreate(
            [
                'team_id' => $team->id,
                'type' => CredentialType::IosCertificate,
                'name' => 'Blue App Store Certificate',
            ],
            ['payload' => ['password' => self::BLUE_IOS_CERTIFICATE_PASSWORD]],
        );
        $provisioningProfile = Credential::updateOrCreate(
            [
                'team_id' => $team->id,
                'type' => CredentialType::ProvisioningProfile,
                'name' => 'Blue App Store Provisioning Profile',
            ],
            ['payload' => null],
        );
        $ios->credentials()->detach([$certificate->id, $provisioningProfile->id]);
        $iosProfile->credentials()->syncWithoutDetaching([
            $certificate->id,
            $provisioningProfile->id,
        ]);
        $certificateMedia = $certificate->getFirstMedia('file');
        $profileMedia = $provisioningProfile->getFirstMedia('file');

        if (
            ! $certificateMedia
            || ! $this->isValidIosCertificate(
                $certificateMedia->getPath(),
                self::BLUE_IOS_CERTIFICATE_PASSWORD,
            )
            || ! $profileMedia
            || ! $this->isValidDemoProvisioningProfile(
                $profileMedia->getPath(),
                self::BLUE_IOS_BUNDLE_IDENTIFIER,
            )
        ) {
            $materials = $this->makeIosSigningMaterials(
                self::BLUE_IOS_BUNDLE_IDENTIFIER,
                self::IOS_TEAM_ID,
                self::BLUE_IOS_CERTIFICATE_PASSWORD,
                'Blue Example App Demo App Store',
                'Apple Distribution: App Manager Blue Demo',
            );
            $certificate->addMediaFromString($materials['certificate'])
                ->usingFileName('blue-distribution.p12')
                ->toMediaCollection('file');
            $provisioningProfile->addMediaFromString($materials['provisioning_profile'])
                ->usingFileName('blue-appstore.mobileprovision')
                ->toMediaCollection('file');
        }

        if (! $app->hasMedia('app_icon')) {
            $app->addMediaFromString($this->makePng(1024, '#0ea5e9'))
                ->usingFileName('blue-icon.png')
                ->toMediaCollection('app_icon');
        }

        if (! $app->hasMedia('splash_icon')) {
            $app->addMediaFromString($this->makePng(2048, '#082f49'))
                ->usingFileName('blue-splash.png')
                ->toMediaCollection('splash_icon');
        }

        $googlePlay = Credential::updateOrCreate(
            [
                'team_id' => $team->id,
                'type' => CredentialType::GooglePlayServiceAccount,
                'name' => 'Blue Google Play Service Account',
            ],
            ['payload' => null],
        );
        $android->credentials()->syncWithoutDetaching([$googlePlay->id]);

        if (! $googlePlay->hasMedia('file')) {
            $googlePlay->addMediaFromString($this->makeDemoServiceAccount('app-manager-blue-demo'))
                ->usingFileName('blue-google-play-service-account.json')
                ->toMediaCollection('file');
        }

        $appleKey = Credential::updateOrCreate(
            [
                'team_id' => $team->id,
                'type' => CredentialType::AppleApiKey,
                'name' => 'Blue App Store Connect API Key',
            ],
            ['payload' => [
                'key_id' => 'BLUEKEY123',
                'issuer_id' => '22222222-2222-4222-8222-222222222222',
            ]],
        );
        $ios->credentials()->syncWithoutDetaching([$appleKey->id]);

        if (! $appleKey->hasMedia('file')) {
            openssl_pkey_export(openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]), $p8);
            $appleKey->addMediaFromString($p8)
                ->usingFileName('blue-appstore-api-key.p8')
                ->toMediaCollection('file');
        }

        $androidFirebase = Credential::updateOrCreate(
            [
                'team_id' => $team->id,
                'type' => CredentialType::GoogleServicesJson,
                'name' => 'Blue Firebase google-services.json',
            ],
            ['payload' => null],
        );
        $android->credentials()->syncWithoutDetaching([$androidFirebase->id]);

        if (! $androidFirebase->hasMedia('file')) {
            $androidFirebase->addMediaFromString($this->makeGoogleServicesJson(
                self::BLUE_ANDROID_IDENTIFIER,
                'app-manager-blue-demo',
            ))
                ->usingFileName('blue-google-services.json')
                ->toMediaCollection('file');
        }

        $iosFirebase = Credential::updateOrCreate(
            [
                'team_id' => $team->id,
                'type' => CredentialType::GoogleServiceInfoPlist,
                'name' => 'Blue Firebase GoogleService-Info.plist',
            ],
            ['payload' => null],
        );
        $ios->credentials()->syncWithoutDetaching([$iosFirebase->id]);

        if (! $iosFirebase->hasMedia('file')) {
            $iosFirebase->addMediaFromString($this->makeGoogleServiceInfoPlist(
                self::BLUE_IOS_BUNDLE_IDENTIFIER,
                'app-manager-blue-demo',
            ))
                ->usingFileName('Blue-GoogleService-Info.plist')
                ->toMediaCollection('file');
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

    private function makeDemoServiceAccount(string $projectId = 'app-manager-demo'): string
    {
        openssl_pkey_export(openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]), $privateKey);

        return json_encode([
            'type' => 'service_account',
            'project_id' => $projectId,
            'private_key_id' => 'demo-key-id',
            'private_key' => $privateKey,
            'client_email' => "demo@{$projectId}.iam.gserviceaccount.com",
            'client_id' => '000000000000000000000',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    private function makeGoogleServicesJson(
        string $packageName = self::IOS_BUNDLE_IDENTIFIER,
        string $projectId = 'app-manager-demo',
    ): string {
        return json_encode([
            'project_info' => [
                'project_number' => '000000000000',
                'project_id' => $projectId,
                'storage_bucket' => "{$projectId}.appspot.com",
            ],
            'client' => [[
                'client_info' => [
                    'mobilesdk_app_id' => '1:000000000000:android:0000000000000000',
                    'android_client_info' => ['package_name' => $packageName],
                ],
                'api_key' => [['current_key' => 'DEMO-ANDROID-API-KEY']],
            ]],
            'configuration_version' => '1',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    private function makeGoogleServiceInfoPlist(
        string $bundleId = self::IOS_BUNDLE_IDENTIFIER,
        string $projectId = 'app-manager-demo',
    ): string {
        return <<<PLIST
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
        <dict>
            <key>BUNDLE_ID</key><string>{$bundleId}</string>
            <key>PROJECT_ID</key><string>{$projectId}</string>
            <key>GOOGLE_APP_ID</key><string>1:000000000000:ios:0000000000000000</string>
            <key>API_KEY</key><string>DEMO-IOS-API-KEY</string>
            <key>GCM_SENDER_ID</key><string>000000000000</string>
            <key>IS_ADS_ENABLED</key><false/>
        </dict>
        </plist>
        PLIST;
    }

    private function isValidDemoKeystore(
        string $path,
        string $password = self::KEYSTORE_PASSWORD,
    ): bool {
        $contents = @file_get_contents($path);
        $certificates = [];

        return is_string($contents)
            && @openssl_pkcs12_read($contents, $certificates, $password)
            && isset($certificates['pkey'], $certificates['cert']);
    }

    private function makeDemoKeystore(
        string $password = self::KEYSTORE_PASSWORD,
        string $alias = self::KEYSTORE_ALIAS,
        string $commonName = 'App Manager Demo',
    ): string {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new \RuntimeException('Unable to generate the demo keystore private key.');
        }

        $subject = [
            'commonName' => $commonName,
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
            $password,
            ['friendly_name' => $alias],
        );

        if (! $exported) {
            throw new \RuntimeException('Unable to export the demo PKCS#12 keystore.');
        }

        return $contents;
    }

    private function isValidIosCertificate(
        string $path,
        string $password = self::IOS_CERTIFICATE_PASSWORD,
    ): bool {
        $contents = @file_get_contents($path);
        $certificates = [];

        return is_string($contents)
            && @openssl_pkcs12_read($contents, $certificates, $password)
            && isset($certificates['pkey'], $certificates['cert']);
    }

    private function isValidDemoProvisioningProfile(
        string $path,
        string $bundleIdentifier = self::IOS_BUNDLE_IDENTIFIER,
        string $teamId = self::IOS_TEAM_ID,
    ): bool {
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
                    '<string>'.$teamId.'.'.$bundleIdentifier.'</string>',
                )
                && str_contains($contents, '<string>'.$teamId.'</string>');
        } finally {
            @unlink($decodedPath);
        }
    }

    private function makeIosSigningMaterials(
        string $bundleIdentifier = self::IOS_BUNDLE_IDENTIFIER,
        string $teamId = self::IOS_TEAM_ID,
        string $certificatePassword = self::IOS_CERTIFICATE_PASSWORD,
        string $profileName = 'Example App Demo App Store',
        string $commonName = 'Apple Distribution: App Manager Demo',
    ): array {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new \RuntimeException('Unable to generate the demo iOS certificate private key.');
        }

        $subject = [
            'commonName' => $commonName.' ('.$teamId.')',
            'organizationName' => 'App Manager',
            'organizationalUnitName' => $teamId,
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
            $certificatePassword,
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
                $bundleIdentifier,
                $teamId,
                $profileName,
            ),
        ];
    }

    private function makeDemoProvisioningProfile(
        \OpenSSLCertificate $certificate,
        \OpenSSLAsymmetricKey $key,
        string $certificateDer,
        string $bundleIdentifier,
        string $teamId,
        string $profileName,
    ): string {
        $createdAt = now()->utc();
        $expiresAt = $createdAt->copy()->addYears(10);
        $encodedCertificate = base64_encode($certificateDer);
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
    <string>{$profileName}</string>
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
}
