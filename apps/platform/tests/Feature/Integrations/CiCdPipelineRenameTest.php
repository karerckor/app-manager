<?php

namespace Tests\Feature\Integrations;

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

class CiCdPipelineRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_renames_a_pipeline_without_github_discovery(): void
    {
        // No CI/CD provider is faked here on purpose: renaming must not touch GitHub.
        [$owner, $team, $integration, $pipeline] = $this->context(TeamRole::Owner);

        $this->actingAs($owner)
            ->patch(route('accounts.integrations.pipelines.rename', [$team, $integration, $pipeline]), [
                'name' => 'Renamed production pipeline',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Pipeline renamed.');

        $pipeline->refresh();
        $this->assertSame('Renamed production pipeline', $pipeline->name);
        $this->assertSame($owner->id, $pipeline->updated_by_user_id);
        // Unrelated configuration stays intact.
        $this->assertSame('octo/mobile', $pipeline->repository_full_name);
        $this->assertSame('200', $pipeline->workflow_id);
    }

    public function test_rename_requires_a_name(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context(TeamRole::Owner);

        $this->actingAs($owner)
            ->patch(route('accounts.integrations.pipelines.rename', [$team, $integration, $pipeline]), [
                'name' => '',
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_contributor_cannot_rename_a_pipeline(): void
    {
        [, $team, $integration, $pipeline] = $this->context(TeamRole::Owner);
        $contributor = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);

        $this->actingAs($contributor)
            ->patch(route('accounts.integrations.pipelines.rename', [$team, $integration, $pipeline]), [
                'name' => 'Nope',
            ])
            ->assertForbidden();

        $this->assertSame('Release', $pipeline->fresh()->name);
    }

    public function test_pipeline_from_another_integration_is_not_found(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context(TeamRole::Owner);
        $otherIntegration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub other',
            'status' => IntegrationStatus::Active,
            'external_id' => '99',
        ]);

        $this->actingAs($owner)
            ->patch(route('accounts.integrations.pipelines.rename', [$team, $otherIntegration, $pipeline]), [
                'name' => 'Nope',
            ])
            ->assertNotFound();

        $this->assertSame('Release', $pipeline->fresh()->name);
    }

    /** @return array{0: User, 1: Team, 2: Integration, 3: CiCdPipeline} */
    private function context(TeamRole $role): array
    {
        $user = User::factory()->create();
        $owner = $role === TeamRole::Owner ? $user : User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($user, $role);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub octo',
            'status' => IntegrationStatus::Active,
            'external_id' => '42',
        ]);
        $pipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'created_by_user_id' => $user->id,
        ]);

        return [$user, $team, $integration, $pipeline];
    }
}
