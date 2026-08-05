<?php

namespace Tests\Feature;

use App\Enums\BuildStatus;
use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_demo_account_graph(): void
    {
        Storage::fake('public');

        $this->seed(DemoSeeder::class);

        $user = User::where('email', 'demo@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Demo123!', $user->password));

        // Personal team, owner role, current team set.
        $team = $user->currentTeam;
        $this->assertNotNull($team);
        $this->assertSame($user->id, $team->owner_id);
        $this->assertSame('owner', $user->teams()->firstOrFail()->pivot->role);

        // Stable Sanctum API key, stored only as a hash and usable immediately.
        $token = $user->tokens()->where('name', 'Demo API Key')->firstOrFail();
        $this->assertSame(hash('sha256', DemoSeeder::API_KEY), $token->token);
        $this->assertSame(['*'], $token->abilities);
        $this->withToken(DemoSeeder::API_KEY)
            ->getJson("/api/v1/{$team->slug}/apps")
            ->assertOk();

        // Example App with Android and iOS release targets.
        $app = MobileApp::where('slug', 'example-app')->firstOrFail();
        $this->assertSame('Example App', $app->name);
        $this->assertSame($team->id, $app->team_id);

        $androidIdentifier = $app->appIdentifiers()
            ->where('platform', Platform::Android)
            ->where('identifier', 'com.example.app')
            ->firstOrFail();
        $this->assertSame('com.example.app', $androidIdentifier->identifier);
        $this->assertSame('1.0.0', $androidIdentifier->version);
        $this->assertSame(1, $androidIdentifier->version_code);
        $this->assertTrue($androidIdentifier->is_default);
        $androidProfile = $androidIdentifier->profiles()->firstOrFail();
        $this->assertSame(ProfileType::Production, $androidProfile->type);

        $iosIdentifier = $app->appIdentifiers()->where('platform', Platform::Ios)->firstOrFail();
        $this->assertSame('com.example.app', $iosIdentifier->identifier);
        $this->assertSame('1.0.0', $iosIdentifier->version);
        $this->assertSame('1', $iosIdentifier->build_number);
        $this->assertTrue($iosIdentifier->is_default);
        $iosProfile = $iosIdentifier->profiles()->firstOrFail();
        $this->assertSame(ProfileType::AppStore, $iosProfile->type);

        // Production keystore credential, attached to its build profile, with file + secrets.
        $keystore = Credential::where('name', 'Production Keystore')->firstOrFail();
        $this->assertSame(CredentialType::AndroidKeystore, $keystore->type);
        $this->assertSame($team->id, $keystore->team_id);
        $this->assertSame('production', $keystore->payload['key_alias']);
        $this->assertSame('DemoKeystore123!', $keystore->payload['store_password']);
        $this->assertSame('DemoKeystore123!', $keystore->payload['key_password']);
        $this->assertFalse($androidIdentifier->credentials()->whereKey($keystore->id)->exists());
        $this->assertTrue($androidProfile->credentials()->whereKey($keystore->id)->exists());
        $this->assertTrue($keystore->hasMedia('file'));

        $media = $keystore->getFirstMedia('file');
        $this->assertSame('production.keystore', $media->file_name);
        $certificates = [];
        $this->assertTrue(openssl_pkcs12_read(file_get_contents($media->getPath()), $certificates, 'DemoKeystore123!'));
        $this->assertArrayHasKey('pkey', $certificates);
        $this->assertArrayHasKey('cert', $certificates);

        // iOS certificate and CMS provisioning profile share the App Store target.
        $iosCertificate = Credential::where('name', 'App Store Certificate')->firstOrFail();
        $this->assertSame(CredentialType::IosCertificate, $iosCertificate->type);
        $this->assertSame('DemoCertificate123!', $iosCertificate->payload['password']);
        $this->assertTrue($iosProfile->credentials()->whereKey($iosCertificate->id)->exists());
        $certificateMedia = $iosCertificate->getFirstMedia('file');
        $this->assertSame('distribution.p12', $certificateMedia->file_name);
        $iosCertificates = [];
        $this->assertTrue(openssl_pkcs12_read(
            file_get_contents($certificateMedia->getPath()),
            $iosCertificates,
            'DemoCertificate123!',
        ));
        $this->assertArrayHasKey('pkey', $iosCertificates);
        $this->assertArrayHasKey('cert', $iosCertificates);

        $provisioningProfile = Credential::where('name', 'App Store Provisioning Profile')->firstOrFail();
        $this->assertSame(CredentialType::ProvisioningProfile, $provisioningProfile->type);
        $this->assertTrue($iosProfile->credentials()->whereKey($provisioningProfile->id)->exists());
        $profileMedia = $provisioningProfile->getFirstMedia('file');
        $this->assertSame('appstore.mobileprovision', $profileMedia->file_name);
        $decodedProfile = tempnam(sys_get_temp_dir(), 'demo-profile-test-');
        $this->assertNotFalse($decodedProfile);

        try {
            $this->assertTrue(openssl_cms_verify(
                $profileMedia->getPath(),
                OPENSSL_CMS_NOVERIFY,
                null,
                [],
                null,
                $decodedProfile,
                null,
                null,
                OPENSSL_ENCODING_DER,
            ));
            $profileContents = file_get_contents($decodedProfile);
            $this->assertStringContainsString(
                '<string>DEMO123456.com.example.app</string>',
                $profileContents,
            );
            $this->assertStringContainsString('<string>DEMO123456</string>', $profileContents);
        } finally {
            @unlink($decodedProfile);
        }

        // App icon and splash media.
        $this->assertTrue($app->hasMedia('app_icon'));
        $this->assertTrue($app->hasMedia('splash_icon'));
        $this->assertSame('icon.png', $app->getFirstMedia('app_icon')->file_name);
        $this->assertSame('splash.png', $app->getFirstMedia('splash_icon')->file_name);

        // Google Play service account, attached directly to the Android identifier.
        $googlePlay = Credential::where('name', 'Google Play Service Account')->firstOrFail();
        $this->assertSame(CredentialType::GooglePlayServiceAccount, $googlePlay->type);
        $this->assertTrue($androidIdentifier->credentials()->whereKey($googlePlay->id)->exists());
        $googlePlayJson = json_decode(file_get_contents($googlePlay->getFirstMedia('file')->getPath()), true);
        $this->assertSame('service_account', $googlePlayJson['type']);
        $this->assertArrayHasKey('private_key', $googlePlayJson);

        // App Store Connect API key, attached directly to the iOS identifier.
        $appleKey = Credential::where('name', 'App Store Connect API Key')->firstOrFail();
        $this->assertSame(CredentialType::AppleApiKey, $appleKey->type);
        $this->assertTrue($iosIdentifier->credentials()->whereKey($appleKey->id)->exists());
        $this->assertSame('DEMOKEY123', $appleKey->payload['key_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $appleKey->payload['issuer_id']);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', file_get_contents($appleKey->getFirstMedia('file')->getPath()));

        // Client Firebase files, one per platform identifier.
        $androidFirebase = Credential::where('name', 'Firebase google-services.json')->firstOrFail();
        $this->assertSame(CredentialType::GoogleServicesJson, $androidFirebase->type);
        $this->assertTrue($androidIdentifier->credentials()->whereKey($androidFirebase->id)->exists());
        $androidFirebaseJson = json_decode(file_get_contents($androidFirebase->getFirstMedia('file')->getPath()), true);
        $this->assertNotEmpty($androidFirebaseJson['project_info']);
        $this->assertNotEmpty($androidFirebaseJson['client']);

        $iosFirebase = Credential::where('name', 'Firebase GoogleService-Info.plist')->firstOrFail();
        $this->assertSame(CredentialType::GoogleServiceInfoPlist, $iosFirebase->type);
        $this->assertTrue($iosIdentifier->credentials()->whereKey($iosFirebase->id)->exists());
        $plistContents = file_get_contents($iosFirebase->getFirstMedia('file')->getPath());
        $this->assertStringContainsString('<key>BUNDLE_ID</key>', $plistContents);
        $this->assertStringContainsString('<key>GOOGLE_APP_ID</key>', $plistContents);

        // Non-default target for deterministic explicit selection.
        $betaIdentifier = $app->appIdentifiers()
            ->where('platform', Platform::Android)
            ->where('identifier', 'com.example.app.beta')
            ->firstOrFail();
        $this->assertFalse($betaIdentifier->is_default);
        $this->assertSame(10100, $betaIdentifier->version_code);
        $betaProfile = $betaIdentifier->profiles()->where('type', ProfileType::Preview)->firstOrFail();
        $betaKeystore = Credential::where('name', 'Beta Preview Keystore')->firstOrFail();
        $this->assertTrue($betaProfile->credentials()->whereKey($betaKeystore->id)->exists());

        // A second application represents another brand built from the same source.
        $blue = MobileApp::where('slug', 'example-app-blue')->firstOrFail();
        $this->assertSame('Blue Example App', $blue->name);
        $this->assertTrue($blue->hasMedia('app_icon'));
        $this->assertTrue($blue->hasMedia('splash_icon'));
        $blueAndroid = $blue->appIdentifiers()
            ->where('platform', Platform::Android)
            ->firstOrFail();
        $blueIos = $blue->appIdentifiers()
            ->where('platform', Platform::Ios)
            ->firstOrFail();
        $this->assertSame('com.example.blue', $blueAndroid->identifier);
        $this->assertSame(20000, $blueAndroid->version_code);
        $this->assertTrue($blueAndroid->is_default);
        $this->assertSame('com.example.blue', $blueIos->identifier);
        $this->assertSame('20000', $blueIos->build_number);
        $this->assertTrue($blueIos->is_default);

        $blueKeystore = Credential::where('name', 'Blue Production Keystore')->firstOrFail();
        $this->assertSame('blue-production', $blueKeystore->payload['key_alias']);
        $this->assertTrue($blueAndroid->profiles()
            ->where('type', ProfileType::Production)
            ->firstOrFail()
            ->credentials()
            ->whereKey($blueKeystore->id)
            ->exists());
        $blueFirebase = Credential::where('name', 'Blue Firebase google-services.json')->firstOrFail();
        $blueFirebaseJson = json_decode(
            file_get_contents($blueFirebase->getFirstMedia('file')->getPath()),
            true,
        );
        $this->assertSame(
            'com.example.blue',
            $blueFirebaseJson['client'][0]['client_info']['android_client_info']['package_name'],
        );

        $this->withToken(DemoSeeder::API_KEY)
            ->getJson("/api/v1/{$team->slug}/apps/example-app-blue/build-context?platform=android&profile_type=production")
            ->assertOk()
            ->assertJsonPath('data.app.slug', 'example-app-blue')
            ->assertJsonPath('data.target.identifier', 'com.example.blue')
            ->assertJsonFragment(['id' => $blueKeystore->id])
            ->assertJsonMissing(['id' => $keystore->id]);

        $this->withToken(DemoSeeder::API_KEY)
            ->getJson("/api/v1/{$team->slug}/apps/example-app/build-context?platform=android&profile_type=preview&identifier=com.example.app.beta")
            ->assertOk()
            ->assertJsonPath('data.target.identifier', 'com.example.app.beta')
            ->assertJsonFragment(['id' => $betaKeystore->id]);

        // Ready-to-browse build history covers successful, failed and running CI runs.
        $completedBuild = $app->buildRecords()->where('ci_run_id', '1001')->firstOrFail();
        $this->assertSame(BuildStatus::Completed, $completedBuild->status);
        $this->assertSame(ProfileType::Production, $completedBuild->profile_type);
        $this->assertSame('com.example.app', $completedBuild->context_snapshot['target']['identifier']);
        $this->assertSame(
            'https://artifacts.example.test/example-app-1.0.0.aab',
            $completedBuild->artifact_url,
        );
        $this->assertNotNull($completedBuild->finished_at);
        $this->assertStringNotContainsString('DemoKeystore123!', json_encode($completedBuild->context_snapshot));

        $this->assertSame(
            BuildStatus::Failed,
            $app->buildRecords()->where('ci_run_id', '1002')->firstOrFail()->status,
        );
        $runningBuild = $app->buildRecords()->where('ci_run_id', '1003')->firstOrFail();
        $this->assertSame(BuildStatus::Running, $runningBuild->status);
        $this->assertSame('com.example.app.beta', $runningBuild->context_snapshot['target']['identifier']);
        $this->assertNull($runningBuild->finished_at);
        $pendingBuild = $app->buildRecords()->where('external_id', 'demo-build-1004')->firstOrFail();
        $this->assertSame(BuildStatus::Pending, $pendingBuild->status);
        $this->assertNull($pendingBuild->started_at);
        $this->assertNull($pendingBuild->ci_run_id);
        $this->assertSame(1, $pendingBuild->logs()->count());

        $this->withToken(DemoSeeder::API_KEY)
            ->getJson("/api/v1/{$team->slug}/apps/example-app/builds")
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_it_is_idempotent(): void
    {
        Storage::fake('public');

        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::where('email', 'demo@example.com')->count());
        $this->assertSame(1, MobileApp::where('slug', 'example-app')->count());
        $this->assertSame(1, Credential::where('name', 'Production Keystore')->count());
        $this->assertSame(1, Credential::where('name', 'App Store Certificate')->count());
        $this->assertSame(1, Credential::where('name', 'App Store Provisioning Profile')->count());
        $this->assertSame(1, Credential::where('name', 'Google Play Service Account')->count());
        $this->assertSame(1, Credential::where('name', 'App Store Connect API Key')->count());
        $this->assertSame(1, Credential::where('name', 'Firebase google-services.json')->count());
        $this->assertSame(1, Credential::where('name', 'Firebase GoogleService-Info.plist')->count());
        $this->assertSame(1, Credential::where('name', 'Beta Preview Keystore')->count());
        $this->assertSame(1, Credential::where('name', 'Blue Production Keystore')->count());
        $this->assertSame(1, MobileApp::where('slug', 'example-app-blue')->count());
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('app_identifiers', 5);
        $this->assertDatabaseCount('profiles', 5);
        $this->assertDatabaseCount('credentials', 15);
        $this->assertDatabaseCount('credentialables', 15);
        $this->assertDatabaseCount('media', 19);
        $this->assertDatabaseCount('build_records', 4);
        $this->assertDatabaseCount('build_logs', 8);
    }

    public function test_database_seeder_is_idempotent(): void
    {
        Storage::fake('public');

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('email', 'test@example.com')->count());
        $this->assertSame(1, User::where('email', 'demo@example.com')->count());
        $this->assertSame(1, Credential::where('name', 'Production Keystore')->count());
        $this->assertSame(1, Credential::where('name', 'App Store Certificate')->count());
        $this->assertSame(1, Credential::where('name', 'App Store Provisioning Profile')->count());
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('media', 19);
        $this->assertDatabaseCount('build_records', 4);
        $this->assertDatabaseCount('build_logs', 8);
    }
}
