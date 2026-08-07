<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Builds\BuildLogArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuildLogDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-local');
        config(['media-library.disk_name' => 'media-local']);
    }

    public function test_member_can_download_active_log_without_ansi_sequences(): void
    {
        [$user, $team, $app] = $this->userWithApp();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'log_output' => "\e[32mBuild passed\e[0m\n\e]8;;https://example.test\e\\Open report\e]8;;\e\\\n",
        ]);

        $this->actingAs($user)
            ->get(route('accounts.apps.builds.logs.download', [$team, $app, $build]))
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=utf-8')
            ->assertDownload("build-{$build->id}.log")
            ->assertContent("Build passed\nOpen report\n");
    }

    public function test_member_can_download_archived_log_without_ansi_sequences(): void
    {
        [$user, $team, $app] = $this->userWithApp();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'log_output' => "\e[31mArchived failure\e[0m\n",
        ]);
        app(BuildLogArchive::class)->archive($build);
        $build->refresh();

        $this->actingAs($user)
            ->get(route('accounts.apps.builds.logs.download', [$team, $app, $build]))
            ->assertOk()
            ->assertDownload("build-{$build->id}.log")
            ->assertContent("Archived failure\n");

        $this->assertSame(
            "\e[31mArchived failure\e[0m\n",
            Storage::disk('media-local')->get($build->log_archive_path),
        );
    }

    public function test_download_is_not_available_to_a_non_member(): void
    {
        [, $team, $app] = $this->userWithApp();
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'log_output' => 'private output',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('accounts.apps.builds.logs.download', [$team, $app, $build]))
            ->assertForbidden();
    }

    /** @return array{User, Team, MobileApp} */
    private function userWithApp(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        return [$user, $team, $app];
    }
}
