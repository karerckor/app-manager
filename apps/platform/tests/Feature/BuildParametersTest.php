<?php

namespace Tests\Feature;

use App\Enums\BuildReportingMode;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\ProfileType;
use App\Enums\TeamRole;
use App\Enums\WebhookEventType;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Contracts\CiCdProvider;
use App\Integrations\CiCd\Data\CancelResult;
use App\Integrations\CiCd\Data\DispatchResult;
use App\Integrations\CiCd\Data\PipelineData;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use App\Integrations\CiCd\Data\RefData;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuildParametersTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_build_can_be_created_from_the_app_page_with_webhook_parameters(): void
    {
        Queue::fake();
        config(['integrations.enabled' => true, 'integrations.webhooks.enabled' => true]);
        [$owner, $team, $app, $identifier] = $this->context();
        $parameters = [
            'channel' => 'internal',
            'release_notes' => 'QA candidate',
            'flags' => ['minify' => true],
        ];

        $this->actingAs($owner)
            ->post(route('accounts.apps.builds.store', [$team, $app]), [
                'request_uuid' => (string) Str::uuid(),
                'app_identifier_id' => $identifier->id,
                'profile_type' => ProfileType::Production->value,
                'parameters' => $parameters,
            ])
            ->assertRedirect(route('accounts.apps.builds.index', [$team, $app]))
            ->assertSessionHas('status', 'Manual build created.');

        $build = BuildRecord::query()->sole();
        $this->assertSame($parameters, $build->parameters);
        $this->assertSame('web', $build->context_snapshot['source']);
        $this->assertSame(BuildReportingMode::Manual, $build->reporting_mode);
        $this->assertDatabaseCount('ci_cd_runs', 0);

        $event = WebhookEvent::query()->where('event_type', WebhookEventType::BuildCreated)->sole();
        $this->assertSame($parameters, $event->payload['parameters']);

        $this->get(route('accounts.apps.builds.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('app.urls.manual_builds', route('accounts.apps.builds.store', [$team, $app]))
                ->where('builds.0.id', $build->id)
                ->where('builds.0.parameters', $parameters));
    }

    public function test_github_actions_inputs_are_saved_displayed_and_added_to_webhooks(): void
    {
        Queue::fake();
        config(['integrations.enabled' => true, 'integrations.webhooks.enabled' => true]);
        [$owner, $team, $app, $identifier] = $this->context();
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
        $this->app->instance(CiCdProviderRegistry::class, new CiCdProviderRegistry([$this->provider()]));
        $parameters = ['channel' => 'production', 'dry_run' => true];

        $this->actingAs($owner)
            ->post(route('accounts.integrations.pipelines.runs.store', [$team, $integration, $pipeline]), [
                'request_uuid' => (string) Str::uuid(),
                'ref' => 'main',
                'inputs' => $parameters,
            ])
            ->assertRedirect();

        $build = BuildRecord::query()->sole();
        $this->assertSame($parameters, $build->parameters);
        $this->assertSame($parameters, $build->ciCdRun->request_snapshot['inputs']);
        $event = WebhookEvent::query()->where('event_type', WebhookEventType::BuildCreated)->sole();
        $this->assertSame($parameters, $event->payload['parameters']);

        $this->get(route('accounts.apps.builds.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pipelines.0.input_schema.channel.type', 'choice')
                ->where('builds.0.parameters', $parameters));
    }

    public function test_user_without_pipeline_permission_cannot_create_a_manual_build(): void
    {
        [, $team, $app, $identifier] = $this->context();
        $contributor = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);

        $this->actingAs($contributor)
            ->post(route('accounts.apps.builds.store', [$team, $app]), [
                'request_uuid' => (string) Str::uuid(),
                'app_identifier_id' => $identifier->id,
                'profile_type' => ProfileType::Production->value,
                'parameters' => ['channel' => 'internal'],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('build_records', 0);
    }

    /** @return array{User, Team, MobileApp, AppIdentifier} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $owner->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => 'android',
        ]);

        return [$owner->fresh(), $team, $app, $identifier];
    }

    private function provider(): CiCdProvider
    {
        return new class implements CiCdProvider
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
    }
}
