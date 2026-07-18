<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Contracts\CiCdProvider;
use App\Integrations\CiCd\Data\PipelineData;
use App\Integrations\CiCd\Data\RefData;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Integrations\CiCd\Data\CancelResult;
use App\Integrations\CiCd\Data\DispatchResult;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiCdPipelineWriteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_updates_and_deletes_a_pipeline(): void
    {
        [$user, $team, $integration, $app] = $this->ctx();
        $this->bindProvider();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesWrite->value])->plainTextToken;
        $base = "/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines";

        $created = $this->withToken($token)->postJson($base, [
            'name' => 'Release',
            'mobile_app_id' => $app->id,
            'profile_type' => 'production',
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'default_ref' => 'main',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson("{$base}/{$created}", [
            'name' => 'Release v2',
            'mobile_app_id' => $app->id,
            'profile_type' => 'production',
            'repository_full_name' => 'octo/mobile',
            'workflow_id' => '200',
            'default_ref' => 'main',
        ])->assertOk()->assertJsonPath('data.name', 'Release v2');

        $this->withToken($token)->deleteJson("{$base}/{$created}")->assertNoContent();
        $this->assertSoftDeleted('ci_cd_pipelines', ['id' => $created]);
    }

    public function test_read_scope_cannot_write(): void
    {
        [$user, $team, $integration, $app] = $this->ctx();
        $this->bindProvider();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesRead->value])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines", ['name' => 'x'])
            ->assertForbidden();
    }

    /** @return array{User, Team, Integration, MobileApp} */
    private function ctx(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub octo',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
        ]);

        return [$user, $team, $integration, $app];
    }

    private function bindProvider(): void
    {
        $provider = new class implements CiCdProvider {
            public function provider(): IntegrationProvider { return IntegrationProvider::GitHub; }
            public function repositories(Integration $i): array { return [new RepositoryData('100', 'mobile', 'octo/mobile', 'main', true, 'https://github.test/octo/mobile')]; }
            public function pipelines(Integration $i, RepositoryData $r): array { return [new PipelineData('200', 'Release', '.github/workflows/release.yml', 'active', 'https://github.test/w/200')]; }
            public function refs(Integration $i, RepositoryData $r): array { return [new RefData('main', 'branch', str_repeat('a', 40))]; }
            public function dispatch(CiCdPipeline $p, string $ref, array $in): DispatchResult { return new DispatchResult('9001', 'u', 'u'); }
            public function cancel(CiCdRun $run): CancelResult { return new CancelResult(true); }
            public function status(CiCdRun $run): ProviderRunStatus { return new ProviderRunStatus('9001', 'completed', 'success', 'u'); }
            public function logs(CiCdRun $run): string { return ''; }
        };
        $this->app->instance(CiCdProviderRegistry::class, new CiCdProviderRegistry([$provider]));
    }
}
