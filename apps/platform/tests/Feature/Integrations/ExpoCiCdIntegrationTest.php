<?php

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\SaveCiCdPipeline;
use App\Actions\Integrations\StartPipelineBuild;
use App\Actions\Integrations\SyncCiCdRunStatus;
use App\Enums\BuildStatus;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\ProfileType;
use App\Enums\TeamRole;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Expo\ExpoProvider;
use App\Jobs\Integrations\DispatchCiCdRun;
use App\Jobs\Integrations\ReconcileCiCdRun;
use App\Jobs\Integrations\SyncCiCdRunLogs;
use App\Models\CiCdPipeline;
use App\Models\ExpoProject;
use App\Models\Integration;
use App\Models\IntegrationOAuthCredential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContextManager;
use App\Support\Builds\BuildLifecycle;
use App\Support\Builds\BuildLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpoCiCdIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('integrations.enabled', true);
        config()->set('integrations.expo.enabled', true);
        config()->set('integrations.expo.api_url', 'https://api.expo.test');
        config()->set('integrations.expo.graphql_url', 'https://api.expo.test/graphql');
        config()->set('integrations.expo.web_url', 'https://expo.test');
    }

    public function test_linked_project_reports_missing_github_and_missing_workflows_separately(): void
    {
        [, , $integration, $project] = $this->context();
        $provider = app(ExpoProvider::class);

        $configurationRequest = 0;
        Http::fake(function () use (&$configurationRequest) {
            $project = $configurationRequest++ === 0
                ? $this->workflowProject(github: false)
                : $this->workflowProject(workflows: false);

            return Http::response(['data' => ['app' => ['byId' => $project]]]);
        });

        $repository = $provider->repositories($integration)[0];
        $this->assertSame('github_not_connected', $repository->readiness);
        $this->assertSame($project->mobile_app_id, $repository->mobileAppId);
        $this->assertSame([], $provider->pipelines($integration, $repository));
        $this->assertSame([], $provider->refs($integration, $repository));

        $repository = $provider->repositories($integration)[0];
        $this->assertSame('workflows_not_configured', $repository->readiness);
        $this->assertSame('acme/atlas-mobile', $repository->sourceRepository);
        $this->assertSame([], $provider->pipelines($integration, $repository));
        $this->assertSame(['main'], collect($provider->refs($integration, $repository))->pluck('name')->all());
    }

    public function test_eas_page_keeps_linked_state_while_explaining_missing_automation_layers(): void
    {
        [$owner, $team, , , $app] = $this->context();
        $configurationRequest = 0;
        Http::fake(function () use (&$configurationRequest) {
            $project = $configurationRequest++ === 0
                ? $this->workflowProject(github: false)
                : $this->workflowProject(workflows: false);

            return Http::response(['data' => ['app' => ['byId' => $project]]]);
        });

        $this->actingAs($owner)
            ->get(route('accounts.apps.expo.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expo.linked', true)
                ->where('expo.remote.automation.state', 'github_not_connected')
                ->where('expo.remote.automation.configured_pipelines', 0));

        $this->get(route('accounts.apps.expo.index', [$team, $app]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expo.linked', true)
                ->where('expo.remote.automation.state', 'workflows_not_configured')
                ->where('expo.remote.automation.github_repository.name', 'acme/atlas-mobile'));
    }

    public function test_eas_workflow_uses_the_standard_pipeline_build_lifecycle(): void
    {
        [$owner, $team, $integration, $project, $app] = $this->context();
        Http::fake(function (HttpRequest $request) {
            if ($request->url() === 'https://api.expo.test/graphql') {
                return Http::response([
                    'data' => ['app' => ['byId' => $this->workflowProject()]],
                ]);
            }

            if ($request->method() === 'POST'
                && $request->url() === 'https://api.expo.test/v2/workflows/dispatch') {
                return Http::response(['data' => [
                    'id' => 'workflow-run-1',
                    'url' => 'https://expo.test/accounts/acme/projects/atlas/workflows/workflow-run-1',
                ]]);
            }

            return Http::response(['data' => [
                'id' => 'workflow-run-1',
                'status' => 'success',
                'url' => 'https://expo.test/accounts/acme/projects/atlas/workflows/workflow-run-1',
                'requestedGitRef' => 'refs/heads/main',
                'createdAt' => '2026-07-29T08:00:00Z',
                'updatedAt' => '2026-07-29T08:05:00Z',
                'jobs' => [[
                    'id' => 'job-1',
                    'key' => 'build_android',
                    'name' => 'Build Android',
                    'type' => 'build',
                    'status' => 'success',
                    'buildId' => 'eas-build-1',
                    'submissionId' => null,
                    'errors' => [],
                ]],
                'errors' => [],
            ]]);
        });

        $this->actingAs($owner)
            ->get(route('accounts.integrations.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('integrations.0.provider', 'expo')
                ->where('integrations.0.provider_name', 'Expo EAS')
                ->where('integrations.0.eligible_app_ids.0', $app->id));

        $this->getJson(route('accounts.integrations.repositories.index', [$team, $integration]))
            ->assertOk()
            ->assertJsonPath('data.0.fullName', '@acme/atlas')
            ->assertJsonPath('data.0.mobileAppId', $app->id)
            ->assertJsonPath('data.0.readiness', 'ready');

        $pipeline = app(SaveCiCdPipeline::class)->handle(
            $team,
            $integration,
            $owner,
            [
                'name' => 'EAS release',
                'mobile_app_id' => $app->id,
                'profile_type' => ProfileType::Production->value,
                'repository_full_name' => $project->full_name,
                'workflow_id' => 'workflow-1',
                'default_ref' => 'main',
                'input_defaults' => ['channel' => 'production'],
            ],
        );

        $this->assertSame($project->external_id, $pipeline->repository_id);
        $this->assertSame('.eas/workflows/deploy.yml', $pipeline->workflow_path);
        $this->assertSame('choice', $pipeline->input_schema['channel']['type']);

        Queue::fake();
        $build = app(StartPipelineBuild::class)->handle(
            $team,
            $integration,
            $pipeline,
            $owner,
            (string) Str::uuid(),
        );
        $run = $build->ciCdRun;
        $registry = app(CiCdProviderRegistry::class);

        (new DispatchCiCdRun($run->id))->handle(
            $registry,
            app(BuildLifecycle::class),
            app(ActorContextManager::class),
        );

        $this->assertSame('workflow-run-1', $run->refresh()->external_run_id);
        $dispatchWasSent = Http::recorded()->contains(fn (array $pair) => $pair[0]->method() === 'POST'
            && $pair[0]->url() === 'https://api.expo.test/v2/workflows/dispatch'
            && data_get($pair[0]->data(), 'appId') === 'project-1'
            && data_get($pair[0]->data(), 'gitRef') === 'main'
            && data_get($pair[0]->data(), 'fileName') === 'deploy.yml'
            && (array) data_get($pair[0]->data(), 'inputs') === ['channel' => 'production']);
        $this->assertTrue($dispatchWasSent);

        (new ReconcileCiCdRun($run->id))->handle(
            $registry,
            app(SyncCiCdRunStatus::class),
        );
        (new SyncCiCdRunLogs($run->id))->handle($registry, app(BuildLog::class));

        $this->assertSame(BuildStatus::Completed, $build->refresh()->status);
        $this->assertStringContainsString('Build Android', (string) $build->log_output);
        $this->assertStringContainsString('EAS build: eas-build-1', (string) $build->log_output);
    }

    public function test_expo_pipeline_cannot_be_bound_to_an_unlinked_application(): void
    {
        [$owner, $team, $integration, $project] = $this->context();
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        Http::fake([
            'https://api.expo.test/graphql' => Http::response([
                'data' => ['app' => ['byId' => $this->workflowProject()]],
            ]),
        ]);

        try {
            app(SaveCiCdPipeline::class)->handle(
                $team,
                $integration,
                $owner,
                [
                    'name' => 'Invalid EAS release',
                    'mobile_app_id' => $otherApp->id,
                    'profile_type' => ProfileType::Production->value,
                    'repository_full_name' => $project->full_name,
                    'workflow_id' => 'workflow-1',
                    'default_ref' => 'main',
                ],
            );
            $this->fail('An EAS workflow was bound to an application linked to another project.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mobile_app_id', $exception->errors());
        }
    }

    public function test_unlinking_an_expo_project_disables_its_pipelines(): void
    {
        [$owner, $team, $integration, $project, $app] = $this->context();
        $pipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'repository_id' => $project->external_id,
            'repository_full_name' => $project->full_name,
        ]);

        $this->actingAs($owner)
            ->delete(route('accounts.apps.expo.unlink', [$team, $app]))
            ->assertRedirect()
            ->assertSessionHas(
                'status',
                'Expo project unlinked and its EAS pipelines disabled. Nothing was deleted on Expo.',
            );

        $this->assertNull($project->refresh()->mobile_app_id);
        $this->assertFalse($pipeline->refresh()->enabled);
    }

    /** @return array{User, Team, Integration, ExpoProject, MobileApp} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::AppService,
            'provider' => IntegrationProvider::Expo,
            'name' => 'Expo acme',
            'status' => IntegrationStatus::Active,
            'external_id' => 'account-1',
            'external_account_id' => 'account-1',
            'external_account_name' => 'acme',
        ]);
        IntegrationOAuthCredential::create([
            'integration_id' => $integration->id,
            'access_token' => 'expo-secret-token',
            'token_type' => 'Bearer',
        ]);
        $project = ExpoProject::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => 'project-1',
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => 'atlas',
            'name' => 'Atlas',
            'full_name' => '@acme/atlas',
            'exists_on_expo' => true,
        ]);

        return [$owner, $team, $integration, $project, $app];
    }

    /** @return array<string, mixed> */
    private function workflowProject(bool $github = true, bool $workflows = true): array
    {
        return [
            'id' => 'project-1',
            'name' => 'Atlas',
            'slug' => 'atlas',
            'fullName' => '@acme/atlas',
            'ownerAccount' => ['id' => 'account-1', 'name' => 'acme'],
            'githubRepository' => $github ? [
                'id' => 'github-repository-1',
                'githubRepositoryUrl' => 'https://github.com/acme/atlas-mobile',
                'metadata' => [
                    'defaultBranch' => 'main',
                    'githubRepoName' => 'atlas-mobile',
                    'githubRepoOwnerName' => 'acme',
                    'githubRepoUrl' => 'https://github.com/acme/atlas-mobile',
                    'private' => true,
                ],
            ] : null,
            'workflows' => $workflows ? [[
                'id' => 'workflow-1',
                'name' => 'Deploy',
                'fileName' => 'deploy.yml',
                'revisionsPaginated' => ['edges' => [[
                    'node' => [
                        'id' => 'revision-1',
                        'commitSha' => str_repeat('a', 40),
                        'yamlConfig' => <<<'YAML'
                            name: Deploy
                            on:
                              workflow_dispatch:
                                inputs:
                                  channel:
                                    type: choice
                                    required: true
                                    default: preview
                                    options: [preview, production]
                            jobs:
                              build_android:
                                type: build
                                params:
                                  platform: android
                            YAML,
                    ],
                ]]],
            ]] : [],
            'workflowRunGitBranchesPaginated' => ['edges' => [[
                'node' => ['name' => 'main', 'lastRunAt' => '2026-07-29T08:00:00Z'],
            ]]],
        ];
    }
}
