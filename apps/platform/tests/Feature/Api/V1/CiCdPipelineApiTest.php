<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiCdPipelineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_and_shows_pipelines(): void
    {
        [$user, $team, $integration, $pipeline] = $this->ctx();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::PipelinesRead->value])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Release')
            ->assertJsonPath('data.0.repository_full_name', 'octo/mobile')
            ->assertJsonPath('meta.total', 1);

        $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/integrations/{$integration->id}/pipelines/{$pipeline->id}")
            ->assertOk()
            ->assertJsonPath('data.workflow_name', 'Release');
    }

    /** @return array{User, Team, Integration, CiCdPipeline} */
    private function ctx(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $integration = Integration::create([
            'team_id' => $team->id, 'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub, 'name' => 'gh',
            'status' => IntegrationStatus::Active, 'external_id' => '42',
        ]);
        $pipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id, 'integration_id' => $integration->id, 'mobile_app_id' => $app->id,
        ]);

        return [$user, $team, $integration, $pipeline];
    }
}
