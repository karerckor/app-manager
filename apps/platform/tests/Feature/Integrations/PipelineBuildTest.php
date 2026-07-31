<?php

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\RequestPipelineBuildCancellation;
use App\Actions\Integrations\StartPipelineBuild;
use App\Actions\Integrations\SyncCiCdRunStatus;
use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\CiCdDispatchStatus;
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
use App\Jobs\Integrations\CancelCiCdRun;
use App\Jobs\Integrations\DispatchCiCdRun;
use App\Jobs\Integrations\ReconcileCiCdRun;
use App\Jobs\Integrations\SyncCiCdRunLogs;
use App\Models\AppIdentifier;
use App\Models\Audit;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit\ActorContextManager;
use App\Support\Builds\BuildLifecycle;
use App\Support\Builds\BuildLog;
use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PipelineBuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_start_request_creates_one_build_run_and_dispatch_job(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context();
        $this->bindProvider($this->provider());
        Queue::fake();
        $requestUuid = (string) Str::uuid();
        $url = route('accounts.integrations.pipelines.runs.store', [$team, $integration, $pipeline]);
        $payload = [
            'request_uuid' => $requestUuid,
            'ref' => 'main',
            'inputs' => ['channel' => 'production'],
        ];

        $this->actingAs($owner)->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertRedirect();

        $this->assertDatabaseCount('build_records', 1);
        $this->assertDatabaseCount('ci_cd_runs', 1);
        $run = CiCdRun::firstOrFail();
        $this->assertSame('production', $run->request_snapshot['inputs']['channel']);
        $this->assertFalse($run->request_snapshot['inputs']['dry_run']);
        Queue::assertPushed(DispatchCiCdRun::class, 1);
    }

    public function test_batch_start_supports_per_application_parameters(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context();
        $secondApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $secondPipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $secondApp->id,
            'created_by_user_id' => $owner->id,
            'input_schema' => $pipeline->input_schema,
        ]);
        $this->bindProvider($this->provider());
        Queue::fake();

        $this->actingAs($owner)
            ->post(route('accounts.integrations.pipelines.batch.runs.store', [$team, $integration]), [
                'runs' => [
                    [
                        'pipeline_id' => $pipeline->id,
                        'request_uuid' => (string) Str::uuid(),
                        'ref' => 'main',
                        'inputs' => ['channel' => 'internal'],
                    ],
                    [
                        'pipeline_id' => $secondPipeline->id,
                        'request_uuid' => (string) Str::uuid(),
                        'ref' => 'main',
                        'inputs' => ['channel' => 'production'],
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '2 builds queued.');

        $this->assertDatabaseCount('build_records', 2);
        $this->assertDatabaseCount('ci_cd_runs', 2);
        $firstRun = CiCdRun::query()->where('pipeline_id', $pipeline->id)->firstOrFail();
        $secondRun = CiCdRun::query()->where('pipeline_id', $secondPipeline->id)->firstOrFail();
        $this->assertSame('internal', $firstRun->request_snapshot['inputs']['channel']);
        $this->assertSame('production', $secondRun->request_snapshot['inputs']['channel']);
        $this->assertSame('main', $firstRun->request_snapshot['ref']);
        $this->assertSame('main', $secondRun->request_snapshot['ref']);
        Queue::assertPushed(DispatchCiCdRun::class, 2);
    }

    public function test_batch_start_rejects_a_pipeline_from_another_integration_without_partial_writes(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context();
        $otherIntegration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::CiCd,
            'provider' => IntegrationProvider::GitHub,
            'name' => 'GitHub other',
            'status' => IntegrationStatus::Active,
            'external_id' => '99',
        ]);
        $otherApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $foreignPipeline = CiCdPipeline::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $otherIntegration->id,
            'mobile_app_id' => $otherApp->id,
            'created_by_user_id' => $owner->id,
        ]);
        $this->bindProvider($this->provider());
        Queue::fake();

        $this->actingAs($owner)
            ->post(route('accounts.integrations.pipelines.batch.runs.store', [$team, $integration]), [
                'runs' => [
                    [
                        'pipeline_id' => $pipeline->id,
                        'request_uuid' => (string) Str::uuid(),
                    ],
                    [
                        'pipeline_id' => $foreignPipeline->id,
                        'request_uuid' => (string) Str::uuid(),
                    ],
                ],
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('build_records', 0);
        $this->assertDatabaseCount('ci_cd_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_dispatch_accepts_exact_provider_run_and_status_sync_is_idempotent(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context();
        $provider = $this->provider();
        $registry = $this->bindProvider($provider);
        Queue::fake();
        $build = app(StartPipelineBuild::class)->handle(
            $team,
            $integration,
            $pipeline,
            $owner,
            (string) Str::uuid(),
        );
        $run = $build->ciCdRun;

        (new DispatchCiCdRun($run->id))->handle($registry, app(BuildLifecycle::class), app(ActorContextManager::class));

        $this->assertSame(CiCdDispatchStatus::Accepted, $run->refresh()->dispatch_status);
        $this->assertSame('9001', $run->external_run_id);
        $this->assertSame(BuildStatus::Pending, $build->refresh()->status);
        Queue::assertPushed(ReconcileCiCdRun::class, fn (ReconcileCiCdRun $job) => $job->runId === $run->id);

        $sync = app(SyncCiCdRunStatus::class);
        $sync->handle($run, new ProviderRunStatus('9001', 'in_progress', null, 'https://github.test/runs/9001'));
        $sync->handle($run, new ProviderRunStatus('9001', 'in_progress', null, 'https://github.test/runs/9001'));
        $this->assertSame(BuildStatus::Running, $build->refresh()->status);
        $startedAt = $build->started_at;

        $sync->handle($run, new ProviderRunStatus('9001', 'completed', 'success', 'https://github.test/runs/9001'));
        $sync->handle($run, new ProviderRunStatus('9001', 'completed', 'success', 'https://github.test/runs/9001'));

        $this->assertSame(BuildStatus::Completed, $build->refresh()->status);
        $this->assertSame($startedAt?->toISOString(), $build->started_at?->toISOString());
        $this->assertNotNull($build->finished_at);
    }

    public function test_completed_first_status_preserves_provider_start_and_finish_times(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $pipeline->mobile_app_id,
            'app_identifier_id' => $pipeline->app_identifier_id,
            'requested_by_user_id' => $owner->id,
        ]);
        $run = CiCdRun::create([
            'team_id' => $team->id,
            'build_record_id' => $build->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $integration->id,
            'requested_by_user_id' => $owner->id,
            'dispatch_status' => CiCdDispatchStatus::Accepted,
            'external_run_id' => '9001',
            'external_run_url' => 'https://github.test/runs/9001',
            'request_snapshot' => ['ref' => 'main', 'inputs' => []],
        ]);
        $startedAt = new DateTimeImmutable('2026-07-18T17:00:01Z');
        $completedAt = new DateTimeImmutable('2026-07-18T17:01:23Z');

        app(SyncCiCdRunStatus::class)->handle(
            $run,
            new ProviderRunStatus(
                '9001',
                'completed',
                'success',
                'https://github.test/runs/9001',
                $startedAt,
                $completedAt,
            ),
        );

        $this->assertSame(BuildStatus::Completed, $build->refresh()->status);
        $this->assertSame($startedAt->format('Y-m-d H:i:s'), $build->started_at?->format('Y-m-d H:i:s'));
        $this->assertSame($completedAt->format('Y-m-d H:i:s'), $build->finished_at?->format('Y-m-d H:i:s'));
    }

    public function test_reconcile_finishes_build_and_synchronizes_provider_logs(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context();
        $provider = $this->provider();
        $registry = $this->bindProvider($provider);
        Queue::fake();
        $build = app(StartPipelineBuild::class)->handle(
            $team,
            $integration,
            $pipeline,
            $owner,
            (string) Str::uuid(),
        );
        $run = $build->ciCdRun;
        (new DispatchCiCdRun($run->id))->handle($registry, app(BuildLifecycle::class), app(ActorContextManager::class));

        (new ReconcileCiCdRun($run->id))->handle($registry, app(SyncCiCdRunStatus::class));

        $this->assertSame(BuildStatus::Completed, $build->refresh()->status);
        Queue::assertPushed(SyncCiCdRunLogs::class, fn (SyncCiCdRunLogs $job) => $job->runId === $run->id);

        (new SyncCiCdRunLogs($run->id))->handle($registry, app(BuildLog::class));

        $this->assertStringContainsString('demo build started', $run->refresh()->log_output);
        $this->assertStringContainsString('demo build started', $build->refresh()->log_output);
        $this->assertNotNull($run->logs_synced_at);

        $audits = Audit::query()
            ->where('auditable_type', BuildRecord::class)
            ->where('auditable_id', $build->id)
            ->where('event', 'updated')
            ->get();

        $this->assertCount(2, $audits);
        $this->assertTrue($audits->every(
            fn (Audit $audit) => $audit->user_id === $owner->id && $audit->source === 'ci_cd',
        ));
    }

    public function test_empty_provider_logs_finish_log_synchronization(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context();
        $provider = $this->provider(logOutput: '');
        $registry = $this->bindProvider($provider);
        Queue::fake();
        $build = app(StartPipelineBuild::class)->handle(
            $team,
            $integration,
            $pipeline,
            $owner,
            (string) Str::uuid(),
        );
        $run = $build->ciCdRun;
        (new DispatchCiCdRun($run->id))->handle($registry, app(BuildLifecycle::class), app(ActorContextManager::class));
        (new ReconcileCiCdRun($run->id))->handle($registry, app(SyncCiCdRunStatus::class));

        (new SyncCiCdRunLogs($run->id))->handle($registry, app(BuildLog::class));

        $this->assertSame('', $build->refresh()->log_output);
        $this->assertNotNull($build->logs_updated_at);
        $this->assertSame('', $run->refresh()->log_output);
        $this->assertNotNull($run->logs_synced_at);
    }

    public function test_ambiguous_dispatch_is_not_automatically_repeated(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context();
        $provider = $this->provider(failDispatch: true);
        $registry = $this->bindProvider($provider);
        Queue::fake();
        $build = app(StartPipelineBuild::class)->handle(
            $team,
            $integration,
            $pipeline,
            $owner,
            (string) Str::uuid(),
        );
        $job = new DispatchCiCdRun($build->ciCdRun->id);

        $job->handle($registry, app(BuildLifecycle::class), app(ActorContextManager::class));
        $job->handle($registry, app(BuildLifecycle::class), app(ActorContextManager::class));

        $this->assertSame(CiCdDispatchStatus::Unknown, $build->ciCdRun->refresh()->dispatch_status);
        $this->assertSame(BuildStatus::Pending, $build->refresh()->status);
        $this->assertSame(1, $provider->dispatchCalls);
    }

    public function test_manual_pipeline_injects_build_id_and_does_not_poll_provider(): void
    {
        [$owner, $team, , $pipeline] = $this->context();
        $schema = $pipeline->input_schema;
        $schema['app_manager_build_id'] = [
            'required' => false,
            'type' => 'string',
            'options' => [],
        ];
        $pipeline->update([
            'reporting_mode' => BuildReportingMode::Manual,
            'build_id_input' => 'app_manager_build_id',
            'input_schema' => $schema,
        ]);
        $provider = $this->provider();
        $registry = $this->bindProvider($provider);
        Queue::fake();

        $build = app(StartPipelineBuild::class)->handle(
            $team,
            $pipeline->integration,
            $pipeline,
            $owner,
            (string) Str::uuid(),
        );
        $run = $build->ciCdRun;

        $this->assertSame(BuildReportingMode::Manual, $build->reporting_mode);
        $this->assertSame(BuildReportingMode::Manual, $run->reporting_mode);
        $this->assertSame($build->id, $run->request_snapshot['inputs']['app_manager_build_id']);

        (new DispatchCiCdRun($run->id))->handle($registry, app(BuildLifecycle::class), app(ActorContextManager::class));

        $this->assertSame(CiCdDispatchStatus::Accepted, $run->refresh()->dispatch_status);
        Queue::assertNotPushed(ReconcileCiCdRun::class);
    }

    public function test_cancellation_before_dispatch_is_terminal_without_external_call(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context();
        $this->bindProvider($this->provider());
        Queue::fake();
        $build = app(StartPipelineBuild::class)->handle(
            $team,
            $integration,
            $pipeline,
            $owner,
            (string) Str::uuid(),
        );

        $result = app(RequestPipelineBuildCancellation::class)->handle($build);

        $this->assertSame(BuildStatus::Cancelled, $result->status);
        $this->assertSame(CiCdDispatchStatus::Failed, $build->ciCdRun->refresh()->dispatch_status);
        Queue::assertNotPushed(CancelCiCdRun::class);
    }

    public function test_cancellation_of_accepted_run_waits_for_provider_confirmation(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context();
        $this->bindProvider($this->provider());
        Queue::fake();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $pipeline->mobile_app_id,
            'app_identifier_id' => $pipeline->app_identifier_id,
            'requested_by_user_id' => $owner->id,
            'status' => BuildStatus::Running,
            'started_at' => now(),
        ]);
        $run = CiCdRun::create([
            'team_id' => $team->id,
            'build_record_id' => $build->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $integration->id,
            'requested_by_user_id' => $owner->id,
            'dispatch_status' => CiCdDispatchStatus::Accepted,
            'external_run_id' => '9001',
            'external_run_url' => 'https://github.test/runs/9001',
            'request_snapshot' => ['ref' => 'main', 'inputs' => []],
        ]);

        $result = app(RequestPipelineBuildCancellation::class)->handle($build);

        $this->assertSame(BuildStatus::CancelRequested, $result->status);
        $this->assertNull($result->finished_at);
        Queue::assertPushed(CancelCiCdRun::class, fn (CancelCiCdRun $job) => $job->runId === $run->id);

        app(SyncCiCdRunStatus::class)->handle(
            $run,
            new ProviderRunStatus('9001', 'completed', 'cancelled', 'https://github.test/runs/9001'),
        );
        $this->assertSame(BuildStatus::Cancelled, $build->refresh()->status);
    }

    public function test_cancellation_during_dispatch_is_sent_after_the_external_run_is_known(): void
    {
        [$owner, $team, $integration, $pipeline] = $this->context();
        Queue::fake();
        $build = null;
        $provider = $this->provider(duringDispatch: function () use (&$build): void {
            app(RequestPipelineBuildCancellation::class)->handle(
                BuildRecord::query()->findOrFail($build->id),
            );
        });
        $registry = $this->bindProvider($provider);
        $build = app(StartPipelineBuild::class)->handle(
            $team,
            $integration,
            $pipeline,
            $owner,
            (string) Str::uuid(),
        );
        $run = $build->ciCdRun;

        (new DispatchCiCdRun($run->id))->handle($registry, app(BuildLifecycle::class), app(ActorContextManager::class));

        $this->assertSame(BuildStatus::CancelRequested, $build->refresh()->status);
        $this->assertSame('9001', $run->refresh()->external_run_id);
        Queue::assertPushed(CancelCiCdRun::class, fn (CancelCiCdRun $job) => $job->runId === $run->id);
        Queue::assertNotPushed(ReconcileCiCdRun::class);
    }

    public function test_contributor_cannot_start_a_pipeline(): void
    {
        [, $team, $integration, $pipeline] = $this->context();
        $contributor = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $this->bindProvider($this->provider());

        $this->actingAs($contributor)
            ->post(route('accounts.integrations.pipelines.runs.store', [$team, $integration, $pipeline]), [
                'request_uuid' => (string) Str::uuid(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('build_records', 0);
    }

    public function test_contributor_cannot_batch_start_pipelines(): void
    {
        [, $team, $integration, $pipeline] = $this->context();
        $contributor = User::factory()->create();
        $team->addUser($contributor, TeamRole::Contributor);
        $this->bindProvider($this->provider());

        $this->actingAs($contributor)
            ->post(route('accounts.integrations.pipelines.batch.runs.store', [$team, $integration]), [
                'runs' => [[
                    'pipeline_id' => $pipeline->id,
                    'request_uuid' => (string) Str::uuid(),
                ]],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('build_records', 0);
    }

    private function context(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, TeamRole::Owner);
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

        return [$owner, $team, $integration, $pipeline];
    }

    private function bindProvider(CiCdProvider $provider): CiCdProviderRegistry
    {
        $registry = new CiCdProviderRegistry([$provider]);
        $this->app->instance(CiCdProviderRegistry::class, $registry);

        return $registry;
    }

    private function provider(
        bool $failDispatch = false,
        ?Closure $duringDispatch = null,
        string $logOutput = "App Manager demo build started\nApp Manager demo build completed successfully",
    ): CiCdProvider {
        return new class($failDispatch, $duringDispatch, $logOutput) implements CiCdProvider
        {
            public int $dispatchCalls = 0;

            public function __construct(
                private readonly bool $failDispatch,
                private readonly ?Closure $duringDispatch,
                private readonly string $logOutput,
            ) {}

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
                $this->dispatchCalls++;

                if ($this->failDispatch) {
                    throw new RuntimeException('Connection dropped after request write.');
                }

                if ($this->duringDispatch !== null) {
                    ($this->duringDispatch)();
                }

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
                return $this->logOutput;
            }
        };
    }
}
