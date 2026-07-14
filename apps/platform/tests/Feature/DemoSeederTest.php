<?php

namespace Tests\Feature;

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

        $androidIdentifier = $app->appIdentifiers()->where('platform', Platform::Android)->firstOrFail();
        $this->assertSame('com.example.app', $androidIdentifier->identifier);
        $androidProfile = $androidIdentifier->profiles()->firstOrFail();
        $this->assertSame(ProfileType::Production, $androidProfile->type);

        $iosIdentifier = $app->appIdentifiers()->where('platform', Platform::Ios)->firstOrFail();
        $this->assertSame('com.example.app', $iosIdentifier->identifier);
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
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('app_identifiers', 2);
        $this->assertDatabaseCount('profiles', 2);
        $this->assertDatabaseCount('credentialables', 7);
        $this->assertDatabaseCount('media', 9);
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
        $this->assertDatabaseCount('media', 9);
    }
}
