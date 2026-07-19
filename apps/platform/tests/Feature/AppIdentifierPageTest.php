<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppIdentifierPageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    public function test_index_lists_app_identifiers(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas iOS',
            'version' => '1.0.0',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.identifiers.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Identifiers/Index')
                ->where('app.id', $app->id)
                ->has('identifiers', 1)
                ->where('identifiers.0.identifier', 'com.example.atlas')
                ->where('identifiers.0.show_url', route('accounts.apps.identifiers.show', [$team, $app, $identifier])));
    }

    public function test_index_forbidden_for_non_member(): void
    {
        [$user] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)
            ->get(route('accounts.apps.identifiers.index', [$foreignApp->team, $foreignApp]))
            ->assertForbidden();
    }
}
