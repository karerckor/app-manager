<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Enums\TeamRole;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppOverviewPageTest extends TestCase
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

    public function test_overview_renders_stats_and_activity(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $app->appIdentifiers()->create(['platform' => Platform::Ios, 'identifier' => 'com.example.atlas']);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => null,
            'profile_type' => ProfileType::Production,
            'status' => 'completed',
            'reporting_mode' => 'manual',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Overview')
                ->where('app.id', $app->id)
                ->where('stats.identifiers', 1)
                ->where('stats.builds', 1)
                ->where('stats.last_build.status', 'completed')
                ->has('activity'));
    }

    public function test_overview_forbidden_for_non_member(): void
    {
        [$user] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)->get(route('accounts.apps.show', [$foreignApp->team, $foreignApp]))
            ->assertForbidden();
    }

    public function test_overview_exposes_pipeline_configuration_scoped_to_the_current_app(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $integration = Integration::factory()->create(['team_id' => $team->id]);
        $pipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'name' => 'Example release',
        ]);
        CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $otherApp->id,
            'name' => 'Other release',
        ]);

        $this->actingAs($user)->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->has('app.pipeline_configuration.integrations', 1)
                ->where('app.pipeline_configuration.integrations.0.id', $integration->id)
                ->has('app.pipeline_configuration.integrations.0.pipelines', 1)
                ->where('app.pipeline_configuration.integrations.0.pipelines.0.id', $pipeline->id)
                ->where('app.pipeline_configuration.integrations.0.pipelines.0.name', 'Example release')
                ->where('app.pipeline_configuration.integrations.0.pipelines.0.mobile_app_id', $app->id));
    }

    public function test_overview_hides_pipeline_configuration_from_members_without_integration_access(): void
    {
        [, $team] = $this->userWithTeam();
        $contributor = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->actingAs($contributor)->get(route('accounts.apps.show', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.pipeline_configuration', null));
    }
}
