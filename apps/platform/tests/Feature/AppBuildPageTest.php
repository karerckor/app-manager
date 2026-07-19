<?php

namespace Tests\Feature;

use App\Enums\ProfileType;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppBuildPageTest extends TestCase
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

    public function test_index_lists_builds(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => null,
            'profile_type' => ProfileType::Production,
            'status' => 'completed',
            'reporting_mode' => 'manual',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.builds.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Builds/Index')
                ->where('app.id', $app->id)
                ->has('builds', 1)
                ->where('builds.0.id', $build->id)
                ->where('builds.0.status', 'completed'));
    }

    public function test_index_forbidden_for_non_member(): void
    {
        [$user] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)->get(route('accounts.apps.builds.index', [$foreignApp->team, $foreignApp]))
            ->assertForbidden();
    }

    public function test_show_returns_single_build(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => null,
            'profile_type' => ProfileType::Production,
            'status' => 'completed',
            'reporting_mode' => 'manual',
            'parameters' => ['channel' => 'internal'],
        ]);

        $this->actingAs($user)->get(route('accounts.apps.builds.show', [$team, $app, $build]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Builds/Show')
                ->where('build.id', $build->id)
                ->where('build.parameters', ['channel' => 'internal']));
    }

    public function test_show_forbidden_for_non_member(): void
    {
        [$user] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();
        $foreignBuild = BuildRecord::factory()->create([
            'team_id' => $foreignApp->team_id,
            'mobile_app_id' => $foreignApp->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => null,
            'profile_type' => ProfileType::Production,
            'status' => 'completed',
            'reporting_mode' => 'manual',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.builds.show', [$foreignApp->team, $foreignApp, $foreignBuild]))
            ->assertForbidden();
    }

    public function test_show_404_when_build_belongs_to_another_app(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $otherApp->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => null,
            'profile_type' => ProfileType::Production,
            'status' => 'completed',
            'reporting_mode' => 'manual',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.builds.show', [$team, $app, $build]))
            ->assertNotFound();
    }
}
