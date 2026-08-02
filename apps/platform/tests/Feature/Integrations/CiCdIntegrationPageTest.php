<?php

namespace Tests\Feature\Integrations;

use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Enums\TeamRole;
use App\Models\AppIdentifier;
use App\Models\CiCdPipeline;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CiCdIntegrationPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_links_connected_providers_to_their_detail_pages(): void
    {
        [$owner, $team] = $this->ownerAndTeam();
        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'name' => 'GitHub Acme',
            'external_account_name' => 'acme',
        ]);
        $firstApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $secondApp = MobileApp::factory()->create(['team_id' => $team->id]);

        CiCdPipeline::factory()->count(2)->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $firstApp->id,
        ]);
        CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $secondApp->id,
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.integrations.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Integrations/Index')
                ->has('integrations', 1)
                ->where('integrations.0.id', $integration->id)
                ->where(
                    'integrations.0.detail_url',
                    route('accounts.integrations.cicd.show', [$team, $integration]),
                )
                ->where('integrations.0.linked_apps_count', 2)
                ->where('integrations.0.pipelines_count', 3)
                ->missing('integrations.0.pipelines'));
    }

    public function test_owner_can_open_a_ci_cd_integration_detail_page(): void
    {
        [$owner, $team] = $this->ownerAndTeam();
        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'name' => 'GitHub Acme',
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.integrations.cicd.show', [$team, $integration]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Integrations/Show')
                ->where('integration.id', $integration->id)
                ->where('integration.name', 'GitHub Acme')
                ->where('integration.index_url', route('accounts.integrations.index', $team))
                ->where('integration.linked_apps_count', 0)
                ->has('pipelines.data', 0)
                ->where('pipelines.total', 0)
                ->where('pipelineFilters.search', '')
                ->missing('apps'));
    }

    public function test_ci_cd_detail_page_paginates_pipeline_bindings(): void
    {
        [$owner, $team] = $this->ownerAndTeam();
        $integration = Integration::factory()->create(['team_id' => $team->id]);
        $apps = MobileApp::factory()->count(27)->create(['team_id' => $team->id]);

        foreach ($apps as $app) {
            CiCdPipeline::factory()->create([
                'team_id' => $team->id,
                'integration_id' => $integration->id,
                'mobile_app_id' => $app->id,
            ]);
        }

        $this->actingAs($owner)
            ->get(route('accounts.integrations.cicd.show', [$team, $integration]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Integrations/Show')
                ->where('integration.linked_apps_count', 27)
                ->where('integration.pipelines_count', 27)
                ->has('pipelines.data', 25)
                ->where('pipelines.current_page', 1)
                ->where('pipelines.last_page', 2)
                ->where('pipelines.total', 27)
                ->missing('apps'));
    }

    public function test_ci_cd_detail_page_searches_and_filters_pipeline_bindings(): void
    {
        [$owner, $team] = $this->ownerAndTeam();
        $integration = Integration::factory()->create(['team_id' => $team->id]);
        $matchingApp = MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Needle Mobile',
        ]);
        $otherApp = MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Other Mobile',
        ]);
        $iosIdentifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $matchingApp->id,
            'platform' => Platform::Ios,
        ]);
        $androidIdentifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $otherApp->id,
            'platform' => Platform::Android,
        ]);
        $matchingPipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $matchingApp->id,
            'app_identifier_id' => $iosIdentifier->id,
            'profile_type' => ProfileType::Production,
            'enabled' => true,
        ]);
        CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $otherApp->id,
            'app_identifier_id' => $androidIdentifier->id,
            'profile_type' => ProfileType::Production,
            'enabled' => false,
        ]);

        $this->actingAs($owner)
            ->get(route('accounts.integrations.cicd.show', [
                $team,
                $integration,
                'search' => 'Needle',
                'platform' => Platform::Ios->value,
                'status' => 'enabled',
                'profile' => ProfileType::Production->value,
            ]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Integrations/Show')
                ->has('pipelines.data', 1)
                ->where('pipelines.total', 1)
                ->where('pipelines.data.0.id', $matchingPipeline->id)
                ->where('pipelines.data.0.platform', Platform::Ios->value)
                ->where('pipelineFilters.search', 'Needle')
                ->where('pipelineFilters.platform', Platform::Ios->value)
                ->where('pipelineFilters.status', 'enabled')
                ->where('pipelineFilters.profile', ProfileType::Production->value));
    }

    public function test_ci_cd_detail_page_rejects_an_integration_from_another_team(): void
    {
        [$owner, $team] = $this->ownerAndTeam();
        $otherTeam = Team::factory()->create();
        $integration = Integration::factory()->create(['team_id' => $otherTeam->id]);

        $this->actingAs($owner)
            ->get(route('accounts.integrations.cicd.show', [$team, $integration]))
            ->assertNotFound();
    }

    public function test_contributors_cannot_open_ci_cd_integration_details(): void
    {
        [$owner, $team] = $this->ownerAndTeam();
        $contributor = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $integration = Integration::factory()->create(['team_id' => $team->id]);

        $this->actingAs($contributor)
            ->get(route('accounts.integrations.cicd.show', [$team, $integration]))
            ->assertForbidden();
    }

    private function ownerAndTeam(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $owner->update(['current_team_id' => $team->id]);

        return [$owner, $team];
    }
}
