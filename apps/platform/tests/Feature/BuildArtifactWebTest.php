<?php

namespace Tests\Feature;

use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuildArtifactWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_and_delete_artifact(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app, $build] = $this->context('owner');

        $this->actingAs($user)->post($this->url($team, $app, $build), [
            'name' => 'release.apk',
            'metadata' => json_encode(['git_sha' => 'abc']),
            'file' => UploadedFile::fake()->create('release.apk', 10),
        ])->assertRedirect();

        $artifact = Artifact::where('build_record_id', $build->id)->firstOrFail();
        $this->assertNotNull($artifact->getFirstMedia('file'));

        $this->actingAs($user)
            ->delete($this->url($team, $app, $build).'/'.$artifact->id)
            ->assertRedirect();
        $this->assertDatabaseMissing('artifacts', ['id' => $artifact->id]);
    }

    public function test_viewer_cannot_upload_artifact(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app, $build] = $this->context('viewer');

        $this->actingAs($user)->post($this->url($team, $app, $build), [
            'name' => 'release.apk',
            'file' => UploadedFile::fake()->create('release.apk', 10),
        ])->assertForbidden();
    }

    public function test_deleting_build_purges_artifact_media_record(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [, $team, , $build] = $this->context('owner');
        $artifact = Artifact::factory()->for($build)->create(['team_id' => $team->id]);
        $artifact->addMedia(UploadedFile::fake()->create('a.apk', 5))->toMediaCollection('file');

        $build->delete();

        $this->assertDatabaseMissing('artifacts', ['id' => $artifact->id]);
        $this->assertDatabaseMissing('media', ['model_id' => $artifact->id]);
    }

    public function test_show_exposes_artifacts_for_a_build(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app, $build] = $this->context('owner');
        $artifact = Artifact::factory()->for($build)->create([
            'team_id' => $team->id,
            'name' => 'release.apk',
            'metadata' => ['git_sha' => 'abc'],
        ]);
        $artifact->addMedia(UploadedFile::fake()->create('release.apk', 7))->toMediaCollection('file');

        $this->actingAs($user)
            ->get(route('accounts.apps.builds.index', [$team, $app]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('builds.0.can_manage_artifacts', true)
                ->where('builds.0.artifacts.0.id', $artifact->id)
                ->where('builds.0.artifacts.0.name', 'release.apk')
                ->where('builds.0.artifacts.0.metadata.git_sha', 'abc'));
    }

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: BuildRecord} */
    private function context(string $role): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create([
            'owner_id' => $role === 'owner' ? $user->id : User::factory()->create()->id,
        ]);
        $team->users()->attach($user, ['role' => $role]);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);

        return [$user, $team, $app, $build];
    }

    private function url(Team $team, MobileApp $app, BuildRecord $build): string
    {
        return "/accounts/{$team->slug}/apps/{$app->slug}/builds/{$build->id}/artifacts";
    }
}
