<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CredentialInputValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_android_keystore_requires_its_passwords_alias_and_file(): void
    {
        [$user, $app, $identifier] = $this->application(Platform::Android);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::AndroidKeystore->value,
            'name' => 'Release keystore',
            'payload' => ['store_password' => 'secret'],
        ])->assertSessionHasErrors([
            'payload.key_alias',
            'payload.key_password',
            'file',
        ]);

        $this->assertDatabaseCount('credentials', 0);
    }

    public function test_android_keystore_rejects_unknown_payload_fields(): void
    {
        [$user, $app, $identifier] = $this->application(Platform::Android);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::AndroidKeystore->value,
            'name' => 'Release keystore',
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
                'unrecognised_secret' => 'must not be stored',
            ],
            'file' => UploadedFile::fake()->createWithContent('release.jks', 'keystore'),
        ])->assertSessionHasErrors('payload');

        $this->assertDatabaseCount('credentials', 0);
    }

    public function test_credential_type_must_support_the_selected_identifier_platform(): void
    {
        [$user, $app, $identifier] = $this->application(Platform::Ios);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::AndroidKeystore->value,
            'profile_type' => ProfileType::Production->value,
            'name' => 'Wrong platform',
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
            ],
            'file' => UploadedFile::fake()->createWithContent('release.jks', 'keystore'),
        ])->assertSessionHasErrors('type');

        $this->assertDatabaseCount('credentials', 0);
    }

    public function test_apns_key_requires_apple_metadata_and_a_valid_private_key(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $app, $identifier] = $this->application(Platform::Ios);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::ApnsKey->value,
            'name' => 'Push key',
            'payload' => ['key_id' => 'ABC123DEFG', 'team_id' => 'TEAM123456'],
            'file' => UploadedFile::fake()->createWithContent('AuthKey.p8', 'not a private key'),
        ])->assertSessionHasErrors('file');

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::ApnsKey->value,
            'name' => 'Push key',
            'payload' => ['key_id' => 'ABC123DEFG', 'team_id' => 'TEAM123456'],
            'file' => UploadedFile::fake()->createWithContent('AuthKey.p8', $this->privateKey()),
        ])->assertSessionHasNoErrors();

        $credential = Credential::firstOrFail();
        $this->assertSame('ABC123DEFG', $credential->payload['key_id']);
        $this->assertSame('TEAM123456', $credential->payload['team_id']);
    }

    public function test_service_account_json_requires_identity_fields_and_a_valid_private_key(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $app, $identifier] = $this->application(Platform::Android);

        $invalid = json_encode([
            'type' => 'service_account',
            'project_id' => 'demo-project',
            'client_email' => 'firebase@example.test',
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::FirebaseConfig->value,
            'name' => 'Firebase HTTP v1',
            'file' => UploadedFile::fake()->createWithContent('firebase.json', $invalid),
        ])->assertSessionHasErrors('file');

        $valid = json_encode([
            'type' => 'service_account',
            'project_id' => 'demo-project',
            'client_email' => 'firebase@example.test',
            'private_key' => $this->privateKey(),
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::FirebaseConfig->value,
            'name' => 'Firebase HTTP v1',
            'file' => UploadedFile::fake()->createWithContent('firebase.json', $valid),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('credentials', [
            'type' => CredentialType::FirebaseConfig->value,
            'name' => 'Firebase HTTP v1',
        ]);
    }

    public function test_ios_certificate_is_verified_with_its_password(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $app, $identifier] = $this->application(Platform::Ios);
        $certificate = $this->pkcs12('correct-password');

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::IosCertificate->value,
            'name' => 'Distribution certificate',
            'profile_type' => ProfileType::AppStore->value,
            'payload' => ['password' => 'wrong-password'],
            'file' => UploadedFile::fake()->createWithContent('distribution.p12', $certificate),
        ])->assertSessionHasErrors('file');

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::IosCertificate->value,
            'name' => 'Distribution certificate',
            'profile_type' => ProfileType::AppStore->value,
            'payload' => ['password' => 'correct-password'],
            'file' => UploadedFile::fake()->createWithContent('distribution.p12', $certificate),
        ])->assertSessionHasNoErrors();

        $this->assertSame('correct-password', Credential::firstOrFail()->payload['password']);
    }

    public function test_replacing_a_file_uses_the_credentials_existing_schema(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $app, $identifier] = $this->application(Platform::Android);
        $credential = Credential::factory()->create([
            'team_id' => $app->team_id,
            'type' => CredentialType::GooglePlayServiceAccount,
        ]);
        $identifier->credentials()->attach($credential);
        $credential->addMediaFromString('{}')->usingFileName('existing.json')->toMediaCollection('file');

        $this->actingAs($user)->post(route('accounts.credentials.upload', [$app->team, $credential]), [
            'file' => UploadedFile::fake()->createWithContent('replacement.json', '{}'),
        ])->assertSessionHasErrors('file');

        $this->assertSame('existing.json', $credential->fresh()->getFirstMedia('file')->file_name);
    }

    public function test_google_services_json_requires_firebase_client_structure(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $app, $identifier] = $this->application(Platform::Android);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::GoogleServicesJson->value,
            'name' => 'Android Firebase',
            'file' => UploadedFile::fake()->createWithContent('google-services.json', '{"project_info":{}}'),
        ])->assertSessionHasErrors('file');

        $valid = json_encode([
            'project_info' => ['project_id' => 'demo', 'project_number' => '123'],
            'client' => [['client_info' => ['mobilesdk_app_id' => '1:123:android:abc']]],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::GoogleServicesJson->value,
            'name' => 'Android Firebase',
            'file' => UploadedFile::fake()->createWithContent('google-services.json', $valid),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('credentials', ['type' => CredentialType::GoogleServicesJson->value]);
    }

    public function test_google_service_info_plist_requires_firebase_keys(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $app, $identifier] = $this->application(Platform::Ios);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::GoogleServiceInfoPlist->value,
            'name' => 'iOS Firebase',
            'file' => UploadedFile::fake()->createWithContent('GoogleService-Info.plist', 'not a plist'),
        ])->assertSessionHasErrors('file');

        $valid = <<<'PLIST'
<?xml version="1.0" encoding="UTF-8"?>
<plist version="1.0"><dict>
<key>BUNDLE_ID</key><string>com.example.app</string>
<key>GOOGLE_APP_ID</key><string>1:123:ios:abc</string>
<key>PROJECT_ID</key><string>demo</string>
</dict></plist>
PLIST;

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::GoogleServiceInfoPlist->value,
            'name' => 'iOS Firebase',
            'file' => UploadedFile::fake()->createWithContent('GoogleService-Info.plist', $valid),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('credentials', ['type' => CredentialType::GoogleServiceInfoPlist->value]);
    }

    public function test_ios_certificate_accepts_an_expiration_date(): void
    {
        Storage::fake('public');
        [$user, $app, $identifier] = $this->application(Platform::Ios);
        $certificate = $this->pkcs12('pw');

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::IosCertificate->value,
            'name' => 'Distribution certificate',
            'profile_type' => ProfileType::AppStore->value,
            'expires_at' => '2027-01-31',
            'payload' => ['password' => 'pw'],
            'file' => UploadedFile::fake()->createWithContent('distribution.p12', $certificate),
        ])->assertSessionHasNoErrors();

        $this->assertSame('2027-01-31', Credential::firstOrFail()->expires_at->toDateString());
    }

    public function test_non_expiring_type_rejects_an_expiration_date(): void
    {
        Storage::fake('public');
        [$user, $app, $identifier] = $this->application(Platform::Android);

        $valid = json_encode([
            'project_info' => ['project_id' => 'demo', 'project_number' => '123'],
            'client' => [['client_info' => ['mobilesdk_app_id' => '1:123:android:abc']]],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::GoogleServicesJson->value,
            'name' => 'Android Firebase',
            'expires_at' => '2027-01-31',
            'file' => UploadedFile::fake()->createWithContent('google-services.json', $valid),
        ])->assertSessionHasErrors('expires_at');

        $this->assertDatabaseCount('credentials', 0);
    }

    private function application(Platform $platform): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => $platform,
        ]);

        return [$user->fresh(), $app, $identifier];
    }

    private function credentialStoreUrl(MobileApp $app): string
    {
        return route('accounts.apps.credentials.store', [$app->team, $app]);
    }

    private function privateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    private function pkcs12(string $password): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $request = openssl_csr_new(['commonName' => 'App Manager Test'], $key);
        $certificate = openssl_csr_sign($request, null, $key, 1);
        openssl_pkcs12_export($certificate, $contents, $key, $password);

        return $contents;
    }
}
