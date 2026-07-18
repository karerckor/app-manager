<?php

namespace Tests\Feature;

use App\Enums\CiCdDispatchStatus;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_owned_pipeline_and_run_relations_use_uuids_and_encrypt_inputs(): void
    {
        [$user, $team, $app, $identifier] = $this->application();
        $integration = $this->integration($team, $user);
        $pipeline = $this->pipeline($integration, $app, $identifier, $user, [
            'release_channel' => 'internal-candidate',
        ]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'requested_by_user_id' => $user->id,
            'profile_type' => ProfileType::Production,
        ]);
        $run = CiCdRun::create([
            'team_id' => $team->id,
            'build_record_id' => $build->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $integration->id,
            'requested_by_user_id' => $user->id,
            'dispatch_status' => CiCdDispatchStatus::Created,
            'request_snapshot' => ['ref' => 'main', 'inputs' => ['release_channel' => 'internal-candidate']],
        ]);

        foreach ([$integration, $pipeline, $run] as $model) {
            $this->assertTrue(Str::isUuid($model->id));
        }

        $this->assertTrue($team->integrations->contains($integration));
        $this->assertTrue($app->ciCdPipelines->contains($pipeline));
        $this->assertTrue($build->ciCdRun->is($run));
        $this->assertSame('internal-candidate', $pipeline->input_defaults['release_channel']);
        $this->assertSame('main', $run->request_snapshot['ref']);

        $rawPipeline = DB::table('ci_cd_pipelines')->where('id', $pipeline->id)->value('input_defaults');
        $rawRun = DB::table('ci_cd_runs')->where('id', $run->id)->value('request_snapshot');
        $this->assertStringNotContainsString('internal-candidate', (string) $rawPipeline);
        $this->assertStringNotContainsString('internal-candidate', (string) $rawRun);
    }

    public function test_pipeline_rejects_resources_from_another_team_or_application(): void
    {
        [$user, $team, $app] = $this->application();
        $integration = $this->integration($team, $user);
        $foreignIdentifier = AppIdentifier::factory()->create();

        $this->expectException(DomainException::class);
        $this->pipeline($integration, $app, $foreignIdentifier, $user);
    }

    public function test_ci_run_rejects_a_build_from_another_team(): void
    {
        [$user, $team, $app, $identifier] = $this->application();
        $integration = $this->integration($team, $user);
        $pipeline = $this->pipeline($integration, $app, $identifier, $user);
        $foreignBuild = BuildRecord::factory()->create();

        $this->expectException(DomainException::class);
        CiCdRun::create([
            'team_id' => $team->id,
            'build_record_id' => $foreignBuild->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $integration->id,
            'dispatch_status' => CiCdDispatchStatus::Created,
            'request_snapshot' => ['ref' => 'main', 'inputs' => []],
        ]);
    }

    public function test_disconnected_integration_preserves_pipeline_and_build_history(): void
    {
        [$user, $team, $app, $identifier] = $this->application();
        $integration = $this->integration($team, $user);
        $pipeline = $this->pipeline($integration, $app, $identifier, $user);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);
        $run = CiCdRun::create([
            'team_id' => $team->id,
            'build_record_id' => $build->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $integration->id,
            'dispatch_status' => CiCdDispatchStatus::Accepted,
            'request_snapshot' => ['ref' => 'main', 'inputs' => []],
        ]);

        $integration->update([
            'status' => IntegrationStatus::Disconnected,
            'disconnected_at' => now(),
        ]);
        $pipeline->update(['enabled' => false]);

        $this->assertDatabaseHas('integrations', ['id' => $integration->id, 'status' => 'disconnected']);
        $this->assertDatabaseHas('ci_cd_pipelines', ['id' => $pipeline->id, 'enabled' => false]);
        $this->assertDatabaseHas('build_records', ['id' => $build->id]);
        $this->assertDatabaseHas('ci_cd_runs', ['id' => $run->id]);
    }

    private function application(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, 'owner');
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);

        return [$user, $team, $app, $identifier];
    }

    private function integration(Team $team, User $user): Integration
    {
        return Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub Example',
            'status' => IntegrationStatus::Active,
            'external_id' => 'installation-42',
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);
    }

    private function pipeline(
        Integration $integration,
        MobileApp $app,
        AppIdentifier $identifier,
        User $user,
        array $inputs = [],
    ): CiCdPipeline {
        return CiCdPipeline::create([
            'team_id' => $integration->team_id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'name' => 'Release Android',
            'profile_type' => ProfileType::Production,
            'enabled' => true,
            'repository_id' => '100',
            'repository_full_name' => 'example/mobile',
            'workflow_id' => '200',
            'workflow_name' => 'Release',
            'workflow_path' => '.github/workflows/release.yml',
            'default_ref' => 'main',
            'input_schema' => [],
            'input_defaults' => $inputs,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);
    }
}
