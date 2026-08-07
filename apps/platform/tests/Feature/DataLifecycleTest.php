<?php

namespace Tests\Feature;

use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Support\Builds\BuildLogArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media-local');
        config([
            'media-library.disk_name' => 'media-local',
            'data-lifecycle.trash_retention_days' => 90,
            'data-lifecycle.credential_trash_retention_days' => 90,
        ]);
    }

    public function test_application_can_be_restored_with_builds_logs_and_artifacts_intact(): void
    {
        [$user, $team] = $this->owner();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'status' => 'completed',
            'log_output' => 'recoverable build log',
        ]);
        app(BuildLogArchive::class)->archive($build);
        $build->refresh();
        $artifact = Artifact::factory()->for($build)->create(['team_id' => $team->id]);
        $artifact->addMedia(UploadedFile::fake()->create('release.apk', 8))->toMediaCollection('file');
        $archivePath = $build->log_archive_path;
        $artifactPath = $artifact->getFirstMedia('file')->getPath();

        $this->actingAs($user)
            ->delete(route('accounts.apps.destroy', [$team, $app]))
            ->assertRedirect(route('accounts.apps.index', $team));

        $this->assertSoftDeleted('mobile_apps', ['id' => $app->id]);
        $this->assertDatabaseHas('build_records', ['id' => $build->id]);
        Storage::disk('media-local')->assertExists($archivePath);
        $this->assertFileExists($artifactPath);

        $this->actingAs($user)
            ->post(route('accounts.apps.restore', [$team, $app->id]))
            ->assertRedirect(route('accounts.apps.show', [$team, $app]));

        $this->assertDatabaseHas('mobile_apps', ['id' => $app->id, 'deleted_at' => null]);
        $this->assertNotNull(BuildRecord::findOrFail($build->id)->log_archive_path);
        $this->assertFileExists($artifactPath);
    }

    public function test_credential_restore_preserves_encrypted_payload_attachments_and_file(): void
    {
        [$user, $team] = $this->owner();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'payload' => ['key_id' => 'recover-me'],
        ]);
        $identifier->credentials()->attach($credential);
        $credential->addMediaFromString('secret-file')->usingFileName('credential.p8')->toMediaCollection('file');

        $this->actingAs($user)
            ->delete(route('accounts.credentials.destroy', [$team, $credential]))
            ->assertRedirect();

        $this->assertSoftDeleted('credentials', ['id' => $credential->id]);
        $this->assertDatabaseHas('credentialables', ['credential_id' => $credential->id]);
        $this->assertDatabaseHas('media', ['model_id' => $credential->id]);

        $this->actingAs($user)
            ->post(route('accounts.credentials.restore', [$team, $credential->id]))
            ->assertRedirect();

        $restored = Credential::findOrFail($credential->id);
        $this->assertSame('recover-me', $restored->payload['key_id']);
        $this->assertTrue($restored->appIdentifiers()->whereKey($identifier->id)->exists());
        $this->assertNotNull($restored->getFirstMedia('file'));
    }

    public function test_purge_waits_for_build_archive_retention_then_deletes_trash_and_storage(): void
    {
        [, $team] = $this->owner();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'status' => 'completed',
            'log_output' => 'purge me',
        ]);
        app(BuildLogArchive::class)->archive($build);
        $build->refresh();
        $archivePath = $build->log_archive_path;
        $artifact = Artifact::factory()->for($build)->create(['team_id' => $team->id]);
        $artifact->addMediaFromString('artifact')->usingFileName('release.apk')->toMediaCollection('file');
        $artifactPath = $artifact->getFirstMedia('file')->getPath();

        $app->delete();
        MobileApp::onlyTrashed()->whereKey($app->id)->update(['deleted_at' => now()->subDays(91)]);

        $this->artisan('data-lifecycle:purge')
            ->expectsOutputToContain('Purged')
            ->assertSuccessful();

        $this->assertSoftDeleted('mobile_apps', ['id' => $app->id]);
        Storage::disk('media-local')->assertExists($archivePath);
        $this->assertFileExists($artifactPath);

        $build->forceFill(['logs_archived_at' => now()->subDays(366)])->saveQuietly();
        config(['build-logs.archive_retention_days' => 365]);
        $this->artisan('build-logs:prune-archives')->assertSuccessful();
        $this->artisan('data-lifecycle:purge')->assertSuccessful();

        $this->assertDatabaseMissing('mobile_apps', ['id' => $app->id]);
        $this->assertDatabaseMissing('build_records', ['id' => $build->id]);
        $this->assertDatabaseMissing('artifacts', ['id' => $artifact->id]);
        Storage::disk('media-local')->assertMissing($archivePath);
        $this->assertFileDoesNotExist($artifactPath);
    }

    /** @return array{User, Team} */
    private function owner(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }
}
