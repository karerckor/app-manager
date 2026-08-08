<?php

namespace Tests\Feature;

use App\Enums\CiCdDispatchStatus;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
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
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.storefront',
        ]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
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
                ->where('builds.0.status', 'completed')
                ->where('builds.0.identifier.identifier', 'com.example.storefront')
                ->where('builds.0.identifier.platform', 'android')
                ->where('builds.0.identifier.platform_label', 'Android')
                ->where('builds.0.identifier.url', route('accounts.apps.identifiers.show', [$team, $app, $identifier])));
    }

    public function test_index_forbidden_for_non_member(): void
    {
        [$user] = $this->userWithTeam();
        $foreignApp = MobileApp::factory()->create();

        $this->actingAs($user)->get(route('accounts.apps.builds.index', [$foreignApp->team, $foreignApp]))
            ->assertForbidden();
    }

    public function test_index_filters_builds_by_tag_status_dates_duration_artifacts_and_reporting_mode(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $integration = Integration::factory()->create(['team_id' => $team->id]);
        $pipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'name' => 'Release',
        ]);
        $alternatePipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'name' => 'Nightly',
        ]);
        $matching = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => null,
            'status' => 'completed',
            'reporting_mode' => 'automatic',
            'tags' => [['release', '#22c55e']],
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2)->addMinutes(12),
        ]);
        CiCdRun::create([
            'team_id' => $team->id,
            'build_record_id' => $matching->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $integration->id,
            'requested_by_user_id' => $user->id,
            'dispatch_status' => CiCdDispatchStatus::Accepted,
            'request_snapshot' => [],
        ]);
        Artifact::factory()->create(['team_id' => $team->id, 'build_record_id' => $matching->id]);
        BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => null,
            'status' => 'completed',
            'reporting_mode' => 'automatic',
            'tags' => ['release'],
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2)->addMinutes(3),
        ]);

        $this->actingAs($user)->get(route('accounts.apps.builds.index', [
            $team,
            $app,
            'tags' => ['release', 'candidate'],
            'statuses' => ['completed', 'failed'],
            'started_from' => now()->subDays(3)->toDateString(),
            'started_to' => now()->subDay()->toDateString(),
            'duration_min' => 10,
            'duration_max' => 15,
            'artifacts' => 'with',
            'reporting_mode' => 'automatic',
            'pipelines' => [$pipeline->id, $alternatePipeline->id],
        ]))->assertInertia(fn (Assert $page) => $page
            ->has('builds', 1)
            ->where('builds.0.id', $matching->id)
            ->where('filters.tags', ['release', 'candidate'])
            ->where('filters.statuses', ['completed', 'failed'])
            ->where('filters.pipelines', [$pipeline->id, $alternatePipeline->id])
            ->where('filters.duration_min', '10'));
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
                ->where('build.state_url', route('accounts.apps.builds.state', [$team, $app, $build]))
                ->where('build.parameters', ['channel' => 'internal']));
    }

    public function test_state_returns_the_latest_build_data_without_an_inertia_page_reload(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'status' => 'running',
            'reporting_mode' => 'manual',
            'log_output' => "--- step: Test ---\nRunning tests",
        ]);

        $this->actingAs($user)
            ->getJson(route('accounts.apps.builds.state', [$team, $app, $build]))
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertJsonPath('build.id', $build->id)
            ->assertJsonPath('build.status', 'running')
            ->assertJsonPath('build.logs', "--- step: Test ---\nRunning tests")
            ->assertJsonPath('build.state_url', route('accounts.apps.builds.state', [$team, $app, $build]));
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
