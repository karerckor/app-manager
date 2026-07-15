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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CredentialUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_a_shared_credential_preserves_all_attachments(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app] = $this->application();
        $firstIdentifier = $this->identifier($app, 'com.example.first');
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $secondIdentifier = $this->identifier($otherApp, 'com.example.second');
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::AndroidKeystore,
            'name' => 'Old keystore',
            'payload' => [
                'store_password' => 'old-store-secret',
                'key_alias' => 'old-alias',
                'key_password' => 'old-key-secret',
            ],
        ]);
        $oldProfile = $firstIdentifier->profiles()->create([
            'type' => ProfileType::Production,
            'name' => 'Production',
        ]);
        $oldProfile->credentials()->attach($credential);
        $secondProfile = $secondIdentifier->profiles()->create([
            'type' => ProfileType::Preview,
            'name' => 'Preview',
        ]);
        $secondProfile->credentials()->attach($credential);
        $credential->addMediaFromString('old-keystore')
            ->usingFileName('old.keystore')
            ->toMediaCollection('file');

        $this->actingAs($user)->post(route('accounts.credentials.update', [$team, $credential]), [
            'type' => CredentialType::AndroidKeystore->value,
            'name' => 'Preview keystore',
            'payload' => [
                'store_password' => '',
                'key_alias' => 'preview-alias',
                'key_password' => '',
            ],
            'file' => UploadedFile::fake()->createWithContent('preview.jks', 'new-keystore'),
        ])->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Credential updated.');

        $credential->refresh();

        $this->assertSame('Preview keystore', $credential->name);
        $this->assertSame([
            'store_password' => 'old-store-secret',
            'key_alias' => 'preview-alias',
            'key_password' => 'old-key-secret',
        ], $credential->payload);
        $this->assertTrue($oldProfile->credentials()->whereKey($credential->id)->exists());
        $this->assertTrue($secondProfile->credentials()->whereKey($credential->id)->exists());
        $this->assertCount(2, $credential->profiles);
        $this->assertSame('preview.jks', $credential->getFirstMedia('file')->file_name);
    }

    public function test_edit_props_include_editable_values_without_exposing_passwords(): void
    {
        [$user, $team, $app] = $this->application();
        $identifier = $this->identifier($app, 'com.example.app');
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::AndroidKeystore,
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
            ],
        ]);
        $profile = $identifier->profiles()->create([
            'type' => ProfileType::Production,
            'name' => 'Production',
        ]);
        $profile->credentials()->attach($credential);

        $this->actingAs($user)->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('identifier.build_targets.0.credentials.0.app_identifier_id', $identifier->id)
                ->where('identifier.build_targets.0.credentials.0.profile.value', ProfileType::Production->value)
                ->where('identifier.build_targets.0.credentials.0.payload.store_password', '')
                ->where('identifier.build_targets.0.credentials.0.payload.key_alias', 'release')
                ->where('identifier.build_targets.0.credentials.0.payload.key_password', '')
                ->where('identifier.build_targets.0.credentials.0.configured_fields', ['store_password', 'key_alias', 'key_password'])
                ->where('identifier.build_targets.0.credentials.0.update_url', route('accounts.credentials.update', [$team, $credential])));
    }

    public function test_unattached_credential_can_be_updated_without_application_context(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team] = $this->application();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Play account',
        ]);
        $credential->addMediaFromString('{}')
            ->usingFileName('play.json')
            ->toMediaCollection('file');

        $this->actingAs($user)->post(route('accounts.credentials.update', [$team, $credential]), [
            'type' => CredentialType::GooglePlayServiceAccount->value,
            'name' => 'Changed account',
        ])->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Changed account', $credential->fresh()->name);
        $this->assertDatabaseCount('credentialables', 0);
    }

    public function test_attached_credential_type_cannot_be_changed(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app] = $this->application();
        $identifier = $this->identifier($app, 'com.example.app');
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::AndroidKeystore,
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
            ],
        ]);
        $profile = $identifier->profiles()->create([
            'type' => ProfileType::Production,
            'name' => 'Production',
        ]);
        $profile->credentials()->attach($credential);
        $credential->addMediaFromString('keystore')
            ->usingFileName('release.keystore')
            ->toMediaCollection('file');

        $this->actingAs($user)->post(route('accounts.credentials.update', [$team, $credential]), [
            'type' => CredentialType::GooglePlayServiceAccount->value,
            'name' => 'Play account',
            'payload' => [],
        ])->assertSessionHasErrors('type');

        $this->assertSame(CredentialType::AndroidKeystore, $credential->fresh()->type);
        $this->assertSame('release.keystore', $credential->fresh()->getFirstMedia('file')->file_name);
        $this->assertTrue($profile->credentials()->whereKey($credential->id)->exists());
    }

    public function test_update_rejects_expiration_on_a_non_expiring_type(): void
    {
        Storage::fake('public');
        [$user, $team, $app] = $this->application();
        $identifier = $this->identifier($app, 'com.example.app');
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Play account',
        ]);
        $identifier->credentials()->attach($credential);
        $credential->addMediaFromString('{}')->usingFileName('sa.json')->toMediaCollection('file');

        $this->actingAs($user)->post(route('accounts.credentials.update', [$team, $credential]), [
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::GooglePlayServiceAccount->value,
            'name' => 'Renamed account',
            'expires_at' => '2027-01-31',
            'payload' => [],
        ])->assertSessionHasErrors('expires_at');

        $this->assertSame('Play account', $credential->fresh()->name);
    }

    public function test_update_clears_expiration_when_changing_to_a_non_expiring_type(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.ios',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ProvisioningProfile,
            'name' => 'Profile',
            'expires_at' => '2027-01-31',
        ]);
        $profile = $identifier->profiles()->create([
            'type' => ProfileType::AppStore,
            'name' => 'App Store',
        ]);
        $profile->credentials()->attach($credential);
        $credential->addMediaFromString('profile')->usingFileName('app.mobileprovision')->toMediaCollection('file');

        $this->actingAs($user->fresh())->post(route('accounts.credentials.update', [$team, $credential]), [
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::ApnsKey->value,
            'name' => 'Push key',
            'payload' => ['key_id' => 'ABC123DEFG', 'team_id' => 'TEAM123456'],
            'file' => UploadedFile::fake()->createWithContent('AuthKey.p8', $this->privateKey()),
        ])->assertSessionHasNoErrors();

        $this->assertNull($credential->fresh()->expires_at);
    }

    public function test_update_stores_expiration_on_an_expiring_type(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.ios',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ProvisioningProfile,
            'name' => 'Profile',
            'expires_at' => '2027-01-31',
        ]);
        $profile = $identifier->profiles()->create([
            'type' => ProfileType::AppStore,
            'name' => 'App Store',
        ]);
        $profile->credentials()->attach($credential);
        $credential->addMediaFromString('profile')->usingFileName('app.mobileprovision')->toMediaCollection('file');

        $this->actingAs($user->fresh())->post(route('accounts.credentials.update', [$team, $credential]), [
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::ProvisioningProfile->value,
            'profile_type' => ProfileType::AppStore->value,
            'name' => 'Profile',
            'expires_at' => '2028-06-01',
            'payload' => [],
            'file' => UploadedFile::fake()->createWithContent('app.mobileprovision', 'profile'),
        ])->assertSessionHasNoErrors();

        $this->assertSame('2028-06-01', $credential->fresh()->expires_at->toDateString());
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

    private function application(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        return [$user->fresh(), $team, $app];
    }

    private function identifier(MobileApp $app, string $identifier): AppIdentifier
    {
        return AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => $identifier,
        ]);
    }
}
