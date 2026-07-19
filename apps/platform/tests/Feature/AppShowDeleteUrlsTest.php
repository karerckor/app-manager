<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\Platform;
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

    public function test_show_page_exposes_delete_urls_for_identifiers_and_credentials(): void
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
                ->where('identifier.services.0.delete_url', route('accounts.credentials.destroy', [$team, $credential]))
                ->where('identifier.services.0.attached_elsewhere', false));
    }

    public function test_show_page_flags_a_credential_shared_with_another_app_as_attached_elsewhere(): void
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
                ->where('identifier.services.0.attached_elsewhere', true));
    }
}
