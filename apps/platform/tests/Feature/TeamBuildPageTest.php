<?php

namespace Tests\Feature;

use App\Enums\CiCdDispatchStatus;
use App\Enums\Platform;
use App\Enums\ProfileType;
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

class TeamBuildPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_builds_from_every_application_and_exposes_monitor_counts(): void
    {
        [$user, $team] = $this->userWithTeam();
        $atlas = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Atlas']);
        $orbit = MobileApp::factory()->create(['team_id' => $team->id, 'name' => 'Orbit']);
        $ios = $atlas->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
        ]);
        $android = $orbit->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.orbit',
        ]);

        BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $atlas->id,
            'app_identifier_id' => $ios->id,
            'requested_by_user_id' => null,
            'profile_type' => ProfileType::Production,
            'status' => 'completed',
            'reporting_mode' => 'manual',
            'created_at' => now()->subMinutes(3),
        ]);
        $pipelineBuild = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $orbit->id,
            'app_identifier_id' => $android->id,
            'requested_by_user_id' => null,
            'profile_type' => ProfileType::Production,
            'status' => 'failed',
            'reporting_mode' => 'automatic',
            'created_at' => now()->subMinutes(2),
        ]);
        $this->attachPipeline($team, $orbit, $android->id, $pipelineBuild, $user);
        $expoBuild = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $atlas->id,
            'app_identifier_id' => $ios->id,
            'requested_by_user_id' => null,
            'profile_type' => ProfileType::Production,
            'status' => 'running',
            'reporting_mode' => 'eas',
            'expo_build_id' => 'expo-123',
            'created_at' => now()->subMinute(),
        ]);
        BuildRecord::factory()->create();

        $this->actingAs($user)->get(route('accounts.builds.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Builds/Index')
                ->where('active', 'builds')
                ->has('builds.data', 3)
                ->where('builds.data.0.id', $expoBuild->id)
                ->where('builds.data.0.source', 'expo')
                ->where('builds.data.1.id', $pipelineBuild->id)
                ->where('builds.data.1.source', 'pipeline')
                ->where('builds.data.1.source_label', 'Release Android')
                ->where('monitor.total', 3)
                ->where('monitor.active', 1)
                ->where('monitor.failed', 1)
                ->where('monitor.completed', 1)
                ->where('monitor.success_rate', 50)
                ->has('filterOptions.apps', 2)
                ->has('filterOptions.identifiers', 2));
    }

    public function test_index_filters_by_application_identifier_platform_source_and_status(): void
    {
        [$user, $team] = $this->userWithTeam();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.release',
        ]);
        $matching = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'requested_by_user_id' => null,
            'profile_type' => ProfileType::Production,
            'status' => 'failed',
            'reporting_mode' => 'automatic',
        ]);
        $this->attachPipeline($team, $app, $identifier->id, $matching, $user);
        BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'requested_by_user_id' => null,
            'profile_type' => ProfileType::Production,
            'status' => 'failed',
            'reporting_mode' => 'manual',
        ]);

        $this->actingAs($user)->get(route('accounts.builds.index', [
            'team' => $team,
            'apps' => [$app->id],
            'identifiers' => [$identifier->id],
            'platforms' => ['android'],
            'sources' => ['pipeline'],
            'statuses' => ['failed'],
        ]))->assertInertia(fn (Assert $page) => $page
            ->has('builds.data', 1)
            ->where('builds.data.0.id', $matching->id)
            ->where('builds.data.0.identifier.platform', 'android')
            ->where('filters.apps', [$app->id])
            ->where('filters.identifiers', [$identifier->id])
            ->where('filters.platforms', ['android'])
            ->where('filters.sources', ['pipeline'])
            ->where('filters.statuses', ['failed'])
            ->where('monitor.total', 1)
            ->where('monitor.failed', 1));

        $this->actingAs($user)->get(route('accounts.builds.index', [
            'team' => $team,
            'search' => substr($matching->request_uuid, 0, 8),
        ]))->assertInertia(fn (Assert $page) => $page
            ->has('builds.data', 1)
            ->where('builds.data.0.id', $matching->id));
    }

    public function test_index_is_forbidden_for_a_non_member(): void
    {
        [$user] = $this->userWithTeam();
        $foreignTeam = Team::factory()->create();

        $this->actingAs($user)
            ->get(route('accounts.builds.index', $foreignTeam))
            ->assertForbidden();
    }

    private function attachPipeline(Team $team, MobileApp $app, string $identifierId, BuildRecord $build, User $user): void
    {
        $integration = Integration::factory()->create(['team_id' => $team->id]);
        $pipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifierId,
            'name' => 'Release Android',
        ]);

        CiCdRun::create([
            'team_id' => $team->id,
            'build_record_id' => $build->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $integration->id,
            'requested_by_user_id' => $user->id,
            'dispatch_status' => CiCdDispatchStatus::Accepted,
            'request_snapshot' => [],
        ]);
    }

    private function userWithTeam(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }
}
