<?php

namespace Tests\Feature;

use App\Enums\ApiKeyScope;
use App\Enums\CiCdDispatchStatus;
use App\Enums\TeamRole;
use App\Models\BuildRecord;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Builds\BuildLog;
use App\Support\Builds\BuildLogArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BuildLogArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-01 12:00:00');
        Storage::fake('media-local');
        config([
            'build-logs.retention_days' => 30,
            'media-library.disk_name' => 'media-local',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_daily_command_archives_expired_logs_for_every_account(): void
    {
        [$firstOld, $firstApp] = $this->buildWithLog('first old log', now()->subDays(31));
        [$secondOld] = $this->buildWithLog('second old log', now()->subDays(45));
        [$recent] = $this->buildWithLog('recent log', now()->subDays(29));
        [$active] = $this->buildWithLog('active log', now()->subDays(45));
        $active->update(['status' => 'running']);
        [$legacy] = $this->buildWithLog('legacy log', now()->subDays(45));
        $legacy->forceFill([
            'created_at' => now()->subDays(45),
            'logs_updated_at' => null,
        ])->save();

        $integration = Integration::factory()->create(['team_id' => $firstOld->team_id]);
        $pipeline = CiCdPipeline::factory()->create([
            'team_id' => $firstOld->team_id,
            'integration_id' => $integration->id,
            'mobile_app_id' => $firstApp->id,
        ]);
        $run = CiCdRun::create([
            'team_id' => $firstOld->team_id,
            'build_record_id' => $firstOld->id,
            'pipeline_id' => $pipeline->id,
            'integration_id' => $integration->id,
            'dispatch_status' => CiCdDispatchStatus::Accepted,
            'external_run_id' => 'run-1',
            'log_output' => 'first old log',
            'logs_synced_at' => $firstOld->logs_updated_at,
            'request_snapshot' => [],
        ]);

        $this->artisan('build-logs:archive')
            ->expectsOutput('Archived 3 build log(s) older than 30 day(s).')
            ->assertSuccessful();

        $firstOld->refresh();
        $secondOld->refresh();
        $recent->refresh();
        $active->refresh();
        $legacy->refresh();

        $this->assertNull($firstOld->log_output);
        $this->assertNull($secondOld->log_output);
        $this->assertSame('recent log', $recent->log_output);
        $this->assertSame('active log', $active->log_output);
        $this->assertNull($legacy->log_output);
        $this->assertNotNull($firstOld->logs_archived_at);
        $this->assertSame(strlen('first old log'), $firstOld->log_archive_size);
        $this->assertNotNull($firstOld->logs_updated_at);
        $this->assertNull($run->refresh()->log_output);

        Storage::disk('media-local')->assertExists($firstOld->log_archive_path);
        Storage::disk('media-local')->assertExists($secondOld->log_archive_path);
        $this->assertSame('first old log', Storage::disk('media-local')->get($firstOld->log_archive_path));
        $this->assertSame('second old log', Storage::disk('media-local')->get($secondOld->log_archive_path));
    }

    public function test_authorized_users_can_download_an_archived_log_from_web_and_api(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'log_output' => "archived output\n",
            'logs_updated_at' => now()->subDays(31),
        ]);
        app(BuildLogArchive::class)->archive($build);
        $build->refresh();

        $this->actingAs($user)
            ->get(route('accounts.apps.builds.logs.archive', [$team, $app, $build]))
            ->assertOk()
            ->assertDownload("build-{$build->id}.log");

        $token = $user->createTeamApiKey($team, 'logs', [ApiKeyScope::LogsRead->value])->plainTextToken;
        $logsUrl = "/api/v1/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/logs";

        $this->withToken($token)->getJson($logsUrl)
            ->assertOk()
            ->assertJsonPath('data.content', '')
            ->assertJsonPath('data.archive_url', route('api.v1.apps.builds.logs.archive', [$team, $app, $build]));

        $this->withToken($token)->get("{$logsUrl}/archive")
            ->assertOk()
            ->assertDownload("build-{$build->id}.log");
    }

    public function test_archive_download_is_not_available_to_another_account(): void
    {
        [$build, $app] = $this->buildWithLog('private log', now()->subDays(31));
        app(BuildLogArchive::class)->archive($build);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('accounts.apps.builds.logs.archive', [$build->team, $app, $build]))
            ->assertForbidden();
    }

    public function test_archived_logs_cannot_be_appended_and_are_deleted_with_the_build(): void
    {
        [$build] = $this->buildWithLog('old log', now()->subDays(31));
        app(BuildLogArchive::class)->archive($build);
        $build->refresh();
        $path = $build->log_archive_path;

        try {
            app(BuildLog::class)->append($build, 'late output');
            $this->fail('Appending to an archived log should fail.');
        } catch (ValidationException $exception) {
            $this->assertSame('This build log has already been archived.', $exception->errors()['content'][0]);
        }

        $build->delete();

        Storage::disk('media-local')->assertMissing($path);
    }

    /** @return array{BuildRecord, MobileApp} */
    private function buildWithLog(string $contents, Carbon $updatedAt): array
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'status' => 'completed',
            'log_output' => $contents,
            'logs_updated_at' => $updatedAt,
        ]);

        return [$build, $app];
    }
}
