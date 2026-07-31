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
use Illuminate\Support\Collection;
use Tests\TestCase;

class CiCdPipelineBatchActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_renames_only_the_selected_pipeline_bindings(): void
    {
        [$owner, $team, $integration, $pipelines] = $this->context();
        $unselected = $this->pipeline($team, $integration, $owner, 'Unselected');

        $this->actingAs($owner)
            ->patch(route('accounts.integrations.pipelines.batch.rename', [$team, $integration]), [
                'pipeline_ids' => $pipelines->pluck('id')->all(),
                'name' => 'Shared release',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '2 pipeline bindings renamed.');

        $this->assertTrue($pipelines->every(
            fn (CiCdPipeline $pipeline) => $pipeline->fresh()->name === 'Shared release'
                && $pipeline->fresh()->updated_by_user_id === $owner->id,
        ));
        $this->assertSame('Unselected', $unselected->fresh()->name);
    }

    public function test_owner_removes_only_the_selected_pipeline_bindings(): void
    {
        [$owner, $team, $integration, $pipelines] = $this->context();
        $unselected = $this->pipeline($team, $integration, $owner, 'Unselected');

        $this->actingAs($owner)
            ->delete(route('accounts.integrations.pipelines.batch.destroy', [$team, $integration]), [
                'pipeline_ids' => $pipelines->pluck('id')->all(),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '2 pipeline bindings removed. Historical builds were preserved.');

        $this->assertSame(2, CiCdPipeline::withTrashed()
            ->whereIn('id', $pipelines->pluck('id'))
            ->whereNotNull('deleted_at')
            ->count());
        $this->assertNotSoftDeleted($unselected);
    }

    public function test_batch_mutation_is_atomic_when_a_pipeline_is_outside_the_integration(): void
    {
        [$owner, $team, $integration, $pipelines] = $this->context();
        $otherIntegration = $this->integration($team, '99');
        $foreignPipeline = $this->pipeline($team, $otherIntegration, $owner, 'Foreign');

        $this->actingAs($owner)
            ->patch(route('accounts.integrations.pipelines.batch.rename', [$team, $integration]), [
                'pipeline_ids' => [$pipelines->first()->id, $foreignPipeline->id],
                'name' => 'Should not apply',
            ])
            ->assertNotFound();

        $this->assertSame('First', $pipelines->first()->fresh()->name);
        $this->assertSame('Foreign', $foreignPipeline->fresh()->name);
    }

    public function test_contributor_cannot_rename_or_remove_selected_pipeline_bindings(): void
    {
        [, $team, $integration, $pipelines] = $this->context();
        $contributor = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $ids = $pipelines->pluck('id')->all();

        $this->actingAs($contributor)
            ->patch(route('accounts.integrations.pipelines.batch.rename', [$team, $integration]), [
                'pipeline_ids' => $ids,
                'name' => 'Forbidden',
            ])
            ->assertForbidden();

        $this->delete(route('accounts.integrations.pipelines.batch.destroy', [$team, $integration]), [
            'pipeline_ids' => $ids,
        ])->assertForbidden();

        $this->assertSame(2, CiCdPipeline::query()->whereIn('id', $ids)->count());
        $this->assertSame('First', $pipelines->first()->fresh()->name);
    }

    /** @return array{0: User, 1: Team, 2: Integration, 3: Collection<int, CiCdPipeline>} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $integration = $this->integration($team, '42');

        return [
            $owner,
            $team,
            $integration,
            collect([
                $this->pipeline($team, $integration, $owner, 'First'),
                $this->pipeline($team, $integration, $owner, 'Second'),
            ]),
        ];
    }

    private function integration(Team $team, string $externalId): Integration
    {
        return Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => "GitHub {$externalId}",
            'status' => IntegrationStatus::Active,
            'external_id' => $externalId,
        ]);
    }

    private function pipeline(
        Team $team,
        Integration $integration,
        User $owner,
        string $name,
    ): CiCdPipeline {
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        return CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'created_by_user_id' => $owner->id,
            'name' => $name,
        ]);
    }
}
