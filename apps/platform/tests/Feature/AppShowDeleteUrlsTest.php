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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppShowDeleteUrlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_page_exposes_identifier_delete_and_credential_detach_urls(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.app',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Play account',
        ]);
        $identifier->credentials()->attach($credential);

        $this->actingAs($user->fresh())
            ->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('identifier.delete_url', route('accounts.apps.identifiers.destroy', [$team, $app, $identifier]))
                ->where(
                    'identifier.services.0.detach_url',
                    route('accounts.apps.identifiers.credentials.detach', [$team, $app, $identifier, $credential]),
                )
                ->missing('identifier.services.0.delete_url'));
    }

    public function test_detaching_shared_credential_preserves_other_attachments_and_vault_record(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.app',
        ]);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $otherIdentifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $otherApp->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.other',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::GooglePlayServiceAccount,
            'name' => 'Shared account',
        ]);
        $identifier->credentials()->attach($credential);
        $otherIdentifier->credentials()->attach($credential);

        $this->actingAs($user->fresh())
            ->get(route('accounts.apps.identifiers.show', [$team, $app, $identifier]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'identifier.services.0.detach_url',
                    route('accounts.apps.identifiers.credentials.detach', [$team, $app, $identifier, $credential]),
                ));

        $this->delete(route('accounts.apps.identifiers.credentials.detach', [
            $team,
            $app,
            $identifier,
            $credential,
        ]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Credential detached. It remains in Credentials Vault.');

        $this->assertFalse($identifier->credentials()->whereKey($credential->id)->exists());
        $this->assertTrue($otherIdentifier->credentials()->whereKey($credential->id)->exists());
        $this->assertDatabaseHas('credentials', ['id' => $credential->id]);
    }

    public function test_profile_scoped_credential_detaches_only_from_selected_build_target(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::IosCertificate,
        ]);
        $appStore = $identifier->profiles()->create([
            'type' => ProfileType::AppStore,
            'name' => 'App Store',
        ]);
        $development = $identifier->profiles()->create([
            'type' => ProfileType::Development,
            'name' => 'Development',
        ]);
        $appStore->credentials()->attach($credential);
        $development->credentials()->attach($credential);

        $this->actingAs($user->fresh())
            ->delete(route('accounts.apps.identifiers.credentials.detach', [
                'team' => $team,
                'mobileApp' => $app,
                'appIdentifier' => $identifier,
                'credential' => $credential,
                'profile_type' => ProfileType::AppStore->value,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($appStore->credentials()->whereKey($credential->id)->exists());
        $this->assertTrue($development->credentials()->whereKey($credential->id)->exists());
        $this->assertDatabaseHas('credentials', ['id' => $credential->id]);
    }
}
