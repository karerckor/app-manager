<?php

namespace Tests\Feature;

use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTagSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_an_app_only_through_its_tag_text(): void
    {
        [$user, $team] = $this->context();

        $tagged = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Zulu']);
        $tagged->syncTags([['Onboarding', '#10b981', 'pulse']])->save();
        MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Yankee']);

        $this->actingAs($user)
            ->get(route('accounts.apps.index', $team).'?search=onb')
            ->assertInertia(fn (Assert $page) => $page
                ->has('apps.data', 1)
                ->where('apps.data.0.name', 'Zulu'));
    }

    public function test_search_does_not_match_a_tag_color_or_animation(): void
    {
        [$user, $team] = $this->context();

        $app = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Zulu']);
        $app->syncTags([['beta', '#10b981', 'pulse']])->save();

        foreach (['10b981', 'pulse'] as $needle) {
            $this->actingAs($user)
                ->get(route('accounts.apps.index', $team)."?search={$needle}")
                ->assertInertia(fn (Assert $page) => $page->has('apps.data', 0));
        }
    }

    public function test_search_still_matches_the_apps_own_columns(): void
    {
        [$user, $team] = $this->context();

        MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Onboarding Kit']);
        MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Yankee']);

        $this->actingAs($user)
            ->get(route('accounts.apps.index', $team).'?search=onboarding')
            ->assertInertia(fn (Assert $page) => $page
                ->has('apps.data', 1)
                ->where('apps.data.0.name', 'Onboarding Kit'));
    }

    /** @return array{User, Team} */
    private function context(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user, $team];
    }
}
