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

    public function test_user_can_fully_update_a_credential_from_an_app(): void
    {
        Storage::fake('public');
        [$user, $team, $app] = $this->application();
        $firstIdentifier = $this->identifier($app, 'com.example.first');
        $secondIdentifier = $this->identifier($app, 'com.example.second');
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
        $credential->addMediaFromString('old-keystore')
            ->usingFileName('old.keystore')
            ->toMediaCollection('file');

        $this->actingAs($user)->post(route('accounts.credentials.update', [$team, $credential]), [
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $secondIdentifier->id,
            'type' => CredentialType::AndroidKeystore->value,
            'profile_type' => ProfileType::Preview->value,
            'name' => 'Preview keystore',
            'expires_at' => '2027-08-09',
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
        $newProfile = $secondIdentifier->profiles()->where('type', ProfileType::Preview)->firstOrFail();

        $this->assertSame('Preview keystore', $credential->name);
        $this->assertSame('2027-08-09', $credential->expires_at->toDateString());
        $this->assertSame([
            'store_password' => 'old-store-secret',
            'key_alias' => 'preview-alias',
            'key_password' => 'old-key-secret',
        ], $credential->payload);
        $this->assertFalse($oldProfile->credentials()->whereKey($credential->id)->exists());
        $this->assertTrue($newProfile->credentials()->whereKey($credential->id)->exists());
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

    public function test_credential_cannot_be_updated_through_an_app_it_is_not_attached_to(): void
    {
        [$user, $team, $app] = $this->application();
        $attachedIdentifier = $this->identifier($app, 'com.example.attached');
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $otherIdentifier = $this->identifier($otherApp, 'com.example.other');
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Play account',
        ]);
        $attachedIdentifier->credentials()->attach($credential);

        $this->actingAs($user)->post(route('accounts.credentials.update', [$team, $credential]), [
            'mobile_app_id' => $otherApp->id,
            'app_identifier_id' => $otherIdentifier->id,
            'type' => CredentialType::GooglePlayServiceAccount->value,
            'name' => 'Changed account',
        ])->assertNotFound();

        $this->assertSame('Play account', $credential->fresh()->name);
        $this->assertTrue($attachedIdentifier->credentials()->whereKey($credential->id)->exists());
    }

    public function test_changing_a_credential_type_requires_a_replacement_file(): void
    {
        Storage::fake('public');
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
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::GooglePlayServiceAccount->value,
            'name' => 'Play account',
            'payload' => [],
        ])->assertSessionHasErrors('file');

        $this->assertSame(CredentialType::AndroidKeystore, $credential->fresh()->type);
        $this->assertSame('release.keystore', $credential->fresh()->getFirstMedia('file')->file_name);
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
