<?php

namespace Tests\Feature\Integrations;

use App\Enums\ApiKeyScope;
use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Enums\WebhookEventType;
use App\Integrations\Expo\ExpoClient;
use App\Jobs\Integrations\ReconcileEasBuild;
use App\Models\BuildRecord;
use App\Models\ExpoProject;
use App\Models\Integration;
use App\Models\IntegrationOAuthCredential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Support\Builds\BuildLifecycle;
use App\Support\Builds\BuildLog;
use ArrayObject;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EasBuildImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('integrations.enabled', true);
        config()->set('integrations.expo.enabled', true);
        config()->set('integrations.expo.graphql_url', 'https://expo.test/graphql');
        config()->set('integrations.expo.api_url', 'https://api.expo.test');
        config()->set('integrations.expo.web_url', 'https://expo.test');
    }

    public function test_expo_page_marks_builds_that_were_already_imported(): void
    {
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app);
        BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => $owner->id,
            'reporting_mode' => BuildReportingMode::Eas,
            'expo_build_id' => 'eas-imported',
        ]);

        Http::fake([
            'https://expo.test/graphql' => Http::response(['data' => ['app' => ['byId' => [
                'id' => 'project-1',
                'name' => $app->name,
                'slug' => $app->slug,
                'fullName' => '@acme/'.$app->slug,
                'ownerAccount' => ['id' => 'account-1', 'name' => 'acme'],
                'builds' => [
                    $this->easBuild('eas-imported'),
                    $this->easBuild('eas-new'),
                ],
                'submissions' => [],
            ]]]]),
        ]);

        $response = $this->actingAs($owner)
            ->get(route('accounts.apps.expo.index', [$team, $app]));

        $response->assertOk();
        $builds = collect($response->viewData('page')['props']['expo']['remote']['builds']);
        $this->assertTrue(
            (bool) $builds->firstWhere('id', 'eas-imported')['already_imported'],
            'An already imported build was not flagged.',
        );
        $this->assertFalse(
            (bool) $builds->firstWhere('id', 'eas-new')['already_imported'],
            'A build that was never imported must not be flagged.',
        );
    }

    public function test_reconcile_stores_real_logs_from_graphql_log_files(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app);
        $build = $this->importedBuild($team, $app, $owner, 'eas-build-1');

        $this->fakeExpo([
            'builds' => [$this->easBuild('eas-build-1', status: 'FINISHED', artifactUrl: 'https://artifacts.test/app.aab')],
            'logFiles' => ['https://logs.test/eas-build-1.txt'],
            'logContent' => '{"name":"worker","phase":"QUEUE","msg":"Build queued"}'."\n"
                .'{"name":"worker","phase":"RUN_GRADLEW","msg":"Running gradle"}'."\n"
                .'not-json plain line',
            'artifactBody' => 'binary-artifact-contents',
        ]);

        (new ReconcileEasBuild($build->id, notify: false))
            ->handle(app(ExpoClient::class), app(BuildLifecycle::class));

        $build->refresh();
        $this->assertSame(BuildStatus::Completed, $build->status);
        $this->assertStringContainsString('Running gradle', (string) $build->log_output);
        $this->assertStringContainsString('not-json plain line', (string) $build->log_output);
        $this->assertStringNotContainsString('"phase":"RUN_GRADLEW"', (string) $build->log_output);
        $this->assertStringNotContainsString('EAS Build: eas-build-1', (string) $build->log_output);
    }

    public function test_reconcile_attaches_artifacts_larger_than_ten_megabytes(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app);
        $build = $this->importedBuild($team, $app, $owner, 'eas-build-2');

        $this->fakeExpo([
            'builds' => [$this->easBuild('eas-build-2', status: 'FINISHED', artifactUrl: 'https://artifacts.test/app.aab')],
            'logFiles' => [],
            'artifactBody' => str_repeat('a', 11 * 1024 * 1024),
        ]);

        (new ReconcileEasBuild($build->id, notify: false))
            ->handle(app(ExpoClient::class), app(BuildLifecycle::class));

        $artifact = $build->refresh()->artifacts()->first();
        $this->assertNotNull($artifact, 'The artifact record was not created.');
        $this->assertNotNull(
            $artifact->getFirstMedia('file'),
            'The artifact media file was not attached.',
        );
    }

    public function test_attached_eas_artifact_records_an_artifact_created_event(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        config()->set('integrations.webhooks.enabled', true);
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app);
        $build = $this->importedBuild($team, $app, $owner, 'eas-build-8');

        $this->fakeExpo([
            'builds' => [$this->easBuild('eas-build-8', status: 'FINISHED', artifactUrl: 'https://artifacts.test/app.aab')],
            'logFiles' => [],
            'artifactBody' => 'binary-artifact-contents',
        ]);

        (new ReconcileEasBuild($build->id))
            ->handle(app(ExpoClient::class), app(BuildLifecycle::class));

        $artifact = $build->refresh()->artifacts()->firstOrFail();
        $this->assertTrue(
            WebhookEvent::query()
                ->where('team_id', $team->id)
                ->where('event_type', WebhookEventType::ArtifactCreated->value)
                ->where('aggregate_id', $artifact->id)
                ->exists(),
            'No artifact.created event was recorded for the EAS artifact.',
        );
    }

    public function test_silent_reconcile_does_not_record_an_artifact_created_event(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        config()->set('integrations.webhooks.enabled', true);
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app);
        $build = $this->importedBuild($team, $app, $owner, 'eas-build-9');

        $this->fakeExpo([
            'builds' => [$this->easBuild('eas-build-9', status: 'FINISHED', artifactUrl: 'https://artifacts.test/app.aab')],
            'logFiles' => [],
            'artifactBody' => 'binary-artifact-contents',
        ]);

        (new ReconcileEasBuild($build->id, notify: false))
            ->handle(app(ExpoClient::class), app(BuildLifecycle::class));

        $this->assertSame(1, $build->refresh()->artifacts()->count());
        $this->assertFalse(
            WebhookEvent::query()
                ->where('team_id', $team->id)
                ->where('event_type', WebhookEventType::ArtifactCreated->value)
                ->exists(),
            'A silent import must not broadcast artifact.created.',
        );
    }

    public function test_failed_artifact_attachment_does_not_leave_an_orphaned_artifact(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        config()->set('media-library.max_file_size', 1024);
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app);
        $build = $this->importedBuild($team, $app, $owner, 'eas-build-3');

        $this->fakeExpo([
            'builds' => [$this->easBuild('eas-build-3', status: 'FINISHED', artifactUrl: 'https://artifacts.test/app.aab')],
            'logFiles' => [],
            'artifactBody' => str_repeat('a', 10 * 1024),
        ]);

        (new ReconcileEasBuild($build->id, notify: false))
            ->handle(app(ExpoClient::class), app(BuildLifecycle::class));

        $this->assertSame(
            0,
            $build->refresh()->artifacts()->count(),
            'A failed media attachment left an orphaned artifact row.',
        );
    }

    public function test_reconcile_backfills_artifacts_and_logs_for_terminal_builds(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app);
        $build = $this->importedBuild($team, $app, $owner, 'eas-build-4', BuildStatus::Completed);
        $build->update(['log_output' => "EAS Build: eas-build-4\nPlatform: ANDROID"]);

        $this->fakeExpo([
            'builds' => [$this->easBuild('eas-build-4', status: 'FINISHED', artifactUrl: 'https://artifacts.test/app.aab')],
            'logFiles' => ['https://logs.test/eas-build-4.txt'],
            'logContent' => 'Real gradle output',
            'artifactBody' => 'binary-artifact-contents',
        ]);

        (new ReconcileEasBuild($build->id, notify: false))
            ->handle(app(ExpoClient::class), app(BuildLifecycle::class));

        $build->refresh();
        $this->assertSame(1, $build->artifacts()->count(), 'The missing artifact was not backfilled.');
        $this->assertStringContainsString('Real gradle output', (string) $build->log_output);
    }

    public function test_reconcile_uses_eas_timestamps_for_build_duration(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app);
        $build = $this->importedBuild($team, $app, $owner, 'eas-build-5');

        $startedAt = now()->subMinutes(40)->startOfSecond();
        $completedAt = now()->subMinutes(18)->startOfSecond();
        $this->fakeExpo([
            'builds' => [$this->easBuild(
                'eas-build-5',
                status: 'FINISHED',
                workerStartedAt: $startedAt->toIso8601String(),
                completedAt: $completedAt->toIso8601String(),
            )],
            'logFiles' => [],
        ]);

        (new ReconcileEasBuild($build->id, notify: false))
            ->handle(app(ExpoClient::class), app(BuildLifecycle::class));

        $build->refresh();
        $this->assertTrue(
            $build->started_at?->equalTo($startedAt),
            "Expected started_at {$startedAt} but got {$build->started_at}.",
        );
        $this->assertTrue(
            $build->finished_at?->equalTo($completedAt),
            "Expected finished_at {$completedAt} but got {$build->finished_at}.",
        );
    }

    public function test_build_page_links_imported_builds_to_eas(): void
    {
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app);
        $imported = $this->importedBuild($team, $app, $owner, 'eas-build-6', BuildStatus::Completed);
        $manual = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->get(route('accounts.apps.builds.show', [$team, $app, $imported]));
        $response->assertOk();
        $this->assertSame(
            'https://expo.test/accounts/acme/projects/'.$app->slug.'/builds/eas-build-6',
            $response->viewData('page')['props']['build']['expo_build_url'],
        );

        $response = $this->actingAs($owner)
            ->get(route('accounts.apps.builds.show', [$team, $app, $manual]));
        $response->assertOk();
        $this->assertNull($response->viewData('page')['props']['build']['expo_build_url']);
    }

    private function importedBuild(
        Team $team,
        MobileApp $app,
        User $user,
        string $expoBuildId,
        BuildStatus $status = BuildStatus::Pending,
    ): BuildRecord {
        return BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => null,
            'requested_by_user_id' => $user->id,
            'request_uuid' => (string) Str::uuid(),
            'status' => $status,
            'reporting_mode' => BuildReportingMode::Eas,
            'expo_build_id' => $expoBuildId,
        ]);
    }

    public function test_reconcile_archives_oversized_eas_logs_instead_of_storing_them_inline(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app);
        $build = $this->importedBuild($team, $app, $owner, 'eas-build-7');

        $oversized = str_repeat("xcodebuild noise line\n", 300_000);
        $this->assertGreaterThan(BuildLog::MAX_TOTAL_BYTES, strlen($oversized));

        $this->fakeExpo([
            'builds' => [$this->easBuild('eas-build-7', status: 'FINISHED')],
            'logFiles' => ['https://logs.test/eas-build-7.txt'],
            'logContent' => $oversized,
        ]);

        (new ReconcileEasBuild($build->id, notify: false))
            ->handle(app(ExpoClient::class), app(BuildLifecycle::class));

        $build->refresh();
        $this->assertNull($build->log_output, 'Oversized logs must not stay inline.');
        $this->assertNotNull($build->logs_archived_at, 'Oversized logs were not archived.');
        $this->assertNotNull($build->log_archive_path);
        Storage::disk((string) config('media-library.disk_name'))
            ->assertExists($build->log_archive_path);

        // A later reconcile run must not resurrect the inline log.
        (new ReconcileEasBuild($build->id, notify: false))
            ->handle(app(ExpoClient::class), app(BuildLifecycle::class));
        $this->assertNull($build->refresh()->log_output);
    }

    public function test_api_registers_external_eas_build_and_polls_until_completion(): void
    {
        Storage::fake((string) config('media-library.disk_name'));
        [$owner, $team] = $this->member();
        $integration = $this->expoIntegration($team);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->project($integration, $app);

        $state = new ArrayObject([
            'builds' => [$this->easBuild('eas-api-1', status: 'IN_PROGRESS')],
            'logFiles' => [],
        ]);
        $this->fakeExpo($state);

        $token = $owner->createTeamApiKey($team, 'Runner', [
            ApiKeyScope::BuildsRead->value,
            ApiKeyScope::BuildsWrite->value,
        ])->plainTextToken;
        $this->withToken($token);

        $response = $this->postJson("/api/v1/{$team->slug}/apps/{$app->slug}/builds", [
            'request_uuid' => (string) Str::uuid(),
            'profile_type' => 'production',
            'reporting_mode' => 'eas',
            'expo_build_id' => 'eas-api-1',
        ]);
        $response->assertCreated()
            ->assertJsonPath('data.status', BuildStatus::Pending->value)
            ->assertJsonPath('data.reporting_mode', 'eas')
            ->assertJsonPath('data.expo_build_id', 'eas-api-1');
        $buildId = $response->json('data.id');

        Queue::assertPushed(
            ReconcileEasBuild::class,
            fn (ReconcileEasBuild $job) => $job->buildId === $buildId,
        );

        // The queue worker releases the unique-until-processing lock when it
        // picks a job up; mimic that before running each tick by hand.
        $runTick = function () use ($buildId): void {
            $job = new ReconcileEasBuild($buildId);
            (new UniqueLock(app(CacheRepository::class)))->release($job);
            $job->handle(app(ExpoClient::class), app(BuildLifecycle::class));
        };

        // First poll tick: EAS still building, so the record starts running
        // and the job schedules its own next tick.
        $runTick();
        $this->assertSame(BuildStatus::Running, BuildRecord::query()->findOrFail($buildId)->status);
        Queue::assertPushed(ReconcileEasBuild::class, 2);

        // EAS finishes; the next tick completes the build.
        $state['builds'] = [$this->easBuild('eas-api-1', status: 'FINISHED')];
        $runTick();

        $this->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/builds/{$buildId}")
            ->assertOk()
            ->assertJsonPath('data.status', BuildStatus::Completed->value);
    }

    /**
     * @param  array{builds: list<array<string, mixed>>, logFiles: list<string>, logContent?: string, artifactBody?: string}|ArrayObject  $remote
     */
    private function fakeExpo(array|ArrayObject $remote): void
    {
        Queue::fake();
        Http::fake(function (HttpRequest $request) use ($remote) {
            if ($request->url() === 'https://expo.test/graphql') {
                $query = (string) data_get($request->data(), 'query');

                if (str_contains($query, 'logFiles')) {
                    return Http::response(['data' => ['builds' => ['byId' => [
                        'id' => (string) data_get($request->data(), 'variables.buildId'),
                        'logFiles' => $remote['logFiles'],
                    ]]]]);
                }

                return Http::response(['data' => ['app' => ['byId' => [
                    'id' => 'project-1',
                    'builds' => $remote['builds'],
                    'submissions' => [],
                ]]]]);
            }

            if (str_starts_with($request->url(), 'https://logs.test/')) {
                return Http::response($remote['logContent'] ?? '', 200, ['Content-Type' => 'text/plain']);
            }

            if (str_starts_with($request->url(), 'https://artifacts.test/')) {
                return Http::response($remote['artifactBody'] ?? '', 200, ['Content-Type' => 'application/octet-stream']);
            }

            return Http::response('not found', 404);
        });
    }

    /** @return array<string, mixed> */
    private function easBuild(
        string $id,
        string $status = 'FINISHED',
        ?string $artifactUrl = null,
        ?string $workerStartedAt = null,
        ?string $completedAt = null,
    ): array {
        return [
            'id' => $id,
            'platform' => 'ANDROID',
            'status' => $status,
            'buildProfile' => 'production',
            'distribution' => 'STORE',
            'appIdentifier' => 'com.example.app',
            'appVersion' => '1.0.0',
            'appBuildVersion' => '1',
            'gitRef' => null,
            'gitCommitHash' => null,
            'message' => null,
            'createdAt' => now()->subHour()->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
            'completedAt' => $completedAt ?? now()->toIso8601String(),
            'workerStartedAt' => $workerStartedAt,
            'canRetry' => false,
            'error' => null,
            'artifacts' => [
                'applicationArchiveUrl' => $artifactUrl,
                'buildUrl' => $artifactUrl,
            ],
        ];
    }

    private function member(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }

    private function expoIntegration(Team $team): Integration
    {
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

        return $integration;
    }

    private function project(
        Integration $integration,
        MobileApp $app,
        string $externalId = 'project-1',
    ): ExpoProject {
        return ExpoProject::create([
            'team_id' => $app->team_id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $app->id,
            'external_id' => $externalId,
            'account_id' => 'account-1',
            'account_name' => 'acme',
            'slug' => $app->slug,
            'name' => $app->name,
            'full_name' => '@acme/'.$app->slug,
            'exists_on_expo' => true,
        ]);
    }
}
