<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Contracts\CiCdProvider;
use App\Integrations\CiCd\Data\CancelResult;
use App\Integrations\CiCd\Data\DispatchResult;
use App\Integrations\CiCd\Data\PipelineData;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use App\Integrations\CiCd\Data\RefData;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationDiscoveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_repositories_workflows_and_refs(): void
    {
        [$user, $team, $integration] = $this->ctx();
        $this->bindProvider();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::IntegrationsRead->value])->plainTextToken;
        $base = "/api/v1/{$team->slug}/integrations/{$integration->id}";

        $this->withToken($token)->getJson("{$base}/repositories")
            ->assertOk()->assertJsonPath('data.0.fullName', 'octo/mobile');
        $this->withToken($token)->getJson("{$base}/workflows?repository=octo/mobile")
            ->assertOk()->assertJsonPath('data.0.name', 'Release');
        $this->withToken($token)->getJson("{$base}/refs?repository=octo/mobile")
            ->assertOk()->assertJsonPath('data.0.name', 'main');
    }

    public function test_unknown_repository_returns_404(): void
    {
        [$user, $team, $integration] = $this->ctx();
        $this->bindProvider();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::IntegrationsRead->value])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/integrations/{$integration->id}/workflows?repository=octo/missing")
            ->assertNotFound();
    }

    /** @return array{User, Team, Integration} */
    private function ctx(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub octo',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
        ]);

        return [$user, $team, $integration];
    }

    private function bindProvider(): void
    {
        $provider = new class implements CiCdProvider
        {
            public function provider(): IntegrationProvider
            {
                return IntegrationProvider::GitHub;
            }

            public function repositories(Integration $i): array
            {
                return [new RepositoryData('100', 'mobile', 'octo/mobile', 'main', true, 'https://github.test/octo/mobile')];
            }

            public function pipelines(Integration $i, RepositoryData $r): array
            {
                return [new PipelineData('200', 'Release', '.github/workflows/release.yml', 'active', 'https://github.test/w/200')];
            }

            public function refs(Integration $i, RepositoryData $r): array
            {
                return [new RefData('main', 'branch', str_repeat('a', 40))];
            }

            public function dispatch(CiCdPipeline $p, string $ref, array $in): DispatchResult
            {
                return new DispatchResult('9001', 'u', 'u');
            }

            public function cancel(CiCdRun $run): CancelResult
            {
                return new CancelResult(true);
            }

            public function status(CiCdRun $run): ProviderRunStatus
            {
                return new ProviderRunStatus('9001', 'completed', 'success', 'u');
            }

            public function logs(CiCdRun $run): string
            {
                return '';
            }
        };
        $this->app->instance(CiCdProviderRegistry::class, new CiCdProviderRegistry([$provider]));
    }
}
