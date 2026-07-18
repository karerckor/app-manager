<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\ProfileType;
use App\Enums\TeamRole;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Contracts\CiCdProvider;
use App\Integrations\CiCd\Data\CancelResult;
use App\Integrations\CiCd\Data\DispatchResult;
use App\Integrations\CiCd\Data\PipelineData;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use App\Integrations\CiCd\Data\RefData;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Jobs\Integrations\DispatchCiCdRun;
use App\Models\AppIdentifier;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PipelineBuildApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_one_build_and_is_idempotent(): void
    {
        [$user, $team, $integration, $pipeline] = $this->ctx();
        $this->bindProvider();
        Queue::fake();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesRun->value])->plainTextToken;
        $uuid = (string) Str::uuid();
        $url = "/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines/{$pipeline->id}/runs";
        $payload = ['request_uuid' => $uuid, 'ref' => 'main', 'inputs' => ['channel' => 'production']];

        $this->withToken($token)->postJson($url, $payload)->assertCreated();
        $this->withToken($token)->postJson($url, $payload)->assertCreated();

        $this->assertDatabaseCount('build_records', 1);
        Queue::assertPushed(DispatchCiCdRun::class, 1);
    }

    public function test_run_scope_and_role_required(): void
    {
        [$user, $team, $integration, $pipeline] = $this->ctx(TeamRole::Contributor);
        $this->bindProvider();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesRun->value])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines/{$pipeline->id}/runs", [
                'request_uuid' => (string) Str::uuid(),
            ])
            ->assertForbidden();
    }

    /** @return array{User, Team, Integration, CiCdPipeline} */
    private function ctx(TeamRole $role = TeamRole::Owner): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);

        $user = $owner;
        if ($role !== TeamRole::Owner) {
            $user = User::factory()->create();
            $team->addUser($user, $role);
        }

        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub octo',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
        ]);
        $pipeline = CiCdPipeline::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'name' => 'Release',
            'profile_type' => ProfileType::Production,
            'enabled' => true,
            'repository_id' => '100',
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'workflow_name' => 'Release',
            'workflow_path' => '.github/workflows/release.yml',
            'default_ref' => 'main',
            'input_schema' => [
                'channel' => ['required' => true, 'default' => 'internal', 'type' => 'choice', 'options' => ['internal', 'production']],
                'dry_run' => ['required' => false, 'default' => false, 'type' => 'boolean', 'options' => []],
            ],
            'input_defaults' => [],
        ]);

        return [$user, $team, $integration, $pipeline];
    }

    private function bindProvider(): void
    {
        $provider = new class implements CiCdProvider
        {
            public function provider(): IntegrationProvider
            {
                return IntegrationProvider::GitHub;
            }

            public function repositories(Integration $integration): array
            {
                return [new RepositoryData('100', 'mobile', 'octo/mobile', 'main', true, 'https://github.test/octo/mobile')];
            }

            public function pipelines(Integration $integration, RepositoryData $repository): array
            {
                return [new PipelineData('200', 'Release', '.github/workflows/release.yml', 'active', 'https://github.test/workflows/200')];
            }

            public function refs(Integration $integration, RepositoryData $repository): array
            {
                return [new RefData('main', 'branch', str_repeat('a', 40))];
            }

            public function dispatch(CiCdPipeline $pipeline, string $ref, array $inputs): DispatchResult
            {
                return new DispatchResult('9001', 'https://api.github.test/runs/9001', 'https://github.test/runs/9001');
            }

            public function cancel(CiCdRun $run): CancelResult
            {
                return new CancelResult(true);
            }

            public function status(CiCdRun $run): ProviderRunStatus
            {
                return new ProviderRunStatus('9001', 'completed', 'success', 'https://github.test/runs/9001');
            }

            public function logs(CiCdRun $run): string
            {
                return '';
            }
        };

        $this->app->instance(CiCdProviderRegistry::class, new CiCdProviderRegistry([$provider]));
    }
}
