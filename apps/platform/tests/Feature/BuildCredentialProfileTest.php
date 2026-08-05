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
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuildCredentialProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_targets_describe_ios_and_android_artifacts(): void
    {
        $ios = collect(BuildProfileSchema::forPlatform(Platform::Ios));
        $android = collect(BuildProfileSchema::forPlatform(Platform::Android));

        $this->assertSame(
            ['development', 'adhoc', 'appstore', 'enterprise'],
            $ios->pluck('value')->all(),
        );
        $this->assertSame('.ipa', $ios->firstWhere('value', 'appstore')['artifact']);
        $this->assertSame(
            ['development', 'preview', 'production'],
            $android->pluck('value')->all(),
        );
        $this->assertSame('.apk', $android->firstWhere('value', 'preview')['artifact']);
        $this->assertSame('.aab', $android->firstWhere('value', 'production')['artifact']);

        $this->assertSame('profile', CredentialSchema::definition(CredentialType::IosCertificate)['attachment']);
        $this->assertSame('profile', CredentialSchema::definition(CredentialType::AndroidKeystore)['attachment']);
        $this->assertSame('identifier', CredentialSchema::definition(CredentialType::ApnsKey)['attachment']);
    }

    public function test_signing_credential_requires_a_build_target(): void
    {
        Storage::fake('public');
        [$user, $app, $identifier] = $this->application(Platform::Android);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::AndroidKeystore->value,
            'name' => 'Preview keystore',
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'preview',
                'key_password' => 'key-secret',
            ],
            'file' => UploadedFile::fake()->createWithContent('preview.jks', 'keystore'),
        ])->assertSessionHasErrors('profile_type');

        $this->assertDatabaseCount('credentials', 0);
    }

    public function test_build_target_must_match_the_identifier_platform(): void
    {
        Storage::fake('public');
        [$user, $app, $identifier] = $this->application(Platform::Android);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::AndroidKeystore->value,
            'profile_type' => ProfileType::AppStore->value,
            'name' => 'Wrong target',
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
            ],
            'file' => UploadedFile::fake()->createWithContent('release.jks', 'keystore'),
        ])->assertSessionHasErrors('profile_type');

        $this->assertDatabaseCount('credentials', 0);
    }

    public function test_ios_provisioning_profile_is_assigned_to_the_selected_build_target(): void
    {
        Storage::fake('public');
        [$user, $app, $identifier] = $this->application(Platform::Ios);

        $this->actingAs($user)->post($this->credentialStoreUrl($app), [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::ProvisioningProfile->value,
            'profile_type' => ProfileType::AdHoc->value,
            'name' => 'QA devices',
            'file' => UploadedFile::fake()->createWithContent('qa.mobileprovision', 'profile-data'),
        ])->assertSessionHasNoErrors();

        $credential = Credential::firstOrFail();
        $profile = $identifier->profiles()->where('type', ProfileType::AdHoc)->firstOrFail();

        $this->assertTrue($profile->credentials()->whereKey($credential->id)->exists());
        $this->assertFalse($identifier->credentials()->whereKey($credential->id)->exists());
    }

    public function test_legacy_signing_credentials_are_moved_to_the_previous_default_build_targets(): void
    {
        $android = AppIdentifier::factory()->create(['platform' => Platform::Android]);
        $androidKeystore = Credential::factory()->create(['type' => CredentialType::AndroidKeystore]);
        $android->credentials()->attach($androidKeystore);

        $ios = AppIdentifier::factory()->create(['platform' => Platform::Ios]);
        $iosCertificate = Credential::factory()->create(['type' => CredentialType::IosCertificate]);
        $ios->credentials()->attach($iosCertificate);

        $migration = require database_path('migrations/2026_07_14_110000_assign_signing_credentials_to_build_profiles.php');
        $migration->up();

        $production = $android->profiles()->where('type', ProfileType::Production)->firstOrFail();
        $appStore = $ios->profiles()->where('type', ProfileType::AppStore)->firstOrFail();

        $this->assertTrue($production->credentials()->whereKey($androidKeystore->id)->exists());
        $this->assertTrue($appStore->credentials()->whereKey($iosCertificate->id)->exists());
        $this->assertFalse($android->credentials()->whereKey($androidKeystore->id)->exists());
        $this->assertFalse($ios->credentials()->whereKey($iosCertificate->id)->exists());
    }

    private function application(Platform $platform): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, 'owner');
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
}
