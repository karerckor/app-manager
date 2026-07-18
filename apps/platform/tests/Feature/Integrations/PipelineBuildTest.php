<?php

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\RequestPipelineBuildCancellation;
use App\Actions\Integrations\StartPipelineBuild;
use App\Actions\Integrations\SyncCiCdRunStatus;
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
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Builds\BuildLifecycle;
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

        (new DispatchCiCdRun($run->id))->handle($registry, app(BuildLifecycle::class));

        $this->assertSame(CiCdDispatchStatus::Accepted, $run->refresh()->dispatch_status);
        $this->assertSame('9001', $run->external_run_id);
        $this->assertSame(BuildStatus::Pending, $build->refresh()->status);

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

        $job->handle($registry, app(BuildLifecycle::class));
        $job->handle($registry, app(BuildLifecycle::class));

        $this->assertSame(CiCdDispatchStatus::Unknown, $build->ciCdRun->refresh()->dispatch_status);
        $this->assertSame(BuildStatus::Pending, $build->refresh()->status);
        $this->assertSame(1, $provider->dispatchCalls);
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

    private function provider(bool $failDispatch = false): CiCdProvider
    {
        return new class($failDispatch) implements CiCdProvider
        {
            public int $dispatchCalls = 0;

            public function __construct(private readonly bool $failDispatch) {}

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
        };
    }
}
