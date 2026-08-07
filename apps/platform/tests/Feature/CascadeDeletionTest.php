<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\AppIdentifier;
use App\Models\Artifact;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Profile;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CascadeDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_team_preserves_its_mobile_apps_and_credentials_for_recovery(): void
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $credential = Credential::factory()->create(['team_id' => $team->id]);

        $team->delete();

        $this->assertSoftDeleted('teams', ['id' => $team->id]);
        $this->assertDatabaseHas('mobile_apps', ['id' => $app->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('credentials', ['id' => $credential->id, 'deleted_at' => null]);
    }

    public function test_deleting_mobile_app_preserves_identifiers_and_profiles(): void
    {
        $app = MobileApp::factory()->create();
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $profile = Profile::factory()->create(['app_identifier_id' => $identifier->id]);

        $app->delete();

        $this->assertSoftDeleted('mobile_apps', ['id' => $app->id]);
        $this->assertDatabaseHas('app_identifiers', ['id' => $identifier->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('profiles', ['id' => $profile->id, 'deleted_at' => null]);
    }

    public function test_deleting_identifier_preserves_shared_credential_attachment(): void
    {
        $credential = Credential::factory()->create();
        $identifier = AppIdentifier::factory()->create();
        $credential->appIdentifiers()->attach($identifier);
        $this->assertDatabaseCount('credentialables', 1);

        $identifier->delete();

        $this->assertSoftDeleted('app_identifiers', ['id' => $identifier->id]);
        $this->assertDatabaseCount('credentialables', 1);
        $this->assertDatabaseHas('credentials', ['id' => $credential->id]);
    }

    public function test_deleting_profile_preserves_its_credentials(): void
    {
        $credential = Credential::factory()->create(['type' => CredentialType::ProvisioningProfile]);
        $profile = Profile::factory()->create();
        $credential->profiles()->attach($profile);
        $this->assertDatabaseCount('credentialables', 1);

        $profile->delete();

        $this->assertSoftDeleted('profiles', ['id' => $profile->id]);
        $this->assertDatabaseCount('credentialables', 1);
        $this->assertDatabaseHas('credentials', ['id' => $credential->id]);
    }

    public function test_deleting_credential_preserves_its_media_file(): void
    {
        Storage::fake(config('media-library.disk_name'));
        $credential = Credential::factory()->create();
        $credential->addMediaFromString('cert-bytes')->usingFileName('cert.p12')->toMediaCollection('file');
        $this->assertDatabaseCount('media', 1);

        $credential->delete();

        $this->assertSoftDeleted('credentials', ['id' => $credential->id]);
        $this->assertDatabaseCount('media', 1);
    }

    public function test_deleting_team_preserves_credential_media(): void
    {
        Storage::fake(config('media-library.disk_name'));
        $team = Team::factory()->create();
        $credential = Credential::factory()->create(['team_id' => $team->id]);
        $credential->addMediaFromString('key-bytes')->usingFileName('key.p8')->toMediaCollection('file');
        $this->assertDatabaseCount('media', 1);

        $team->delete();

        $this->assertSoftDeleted('teams', ['id' => $team->id]);
        $this->assertDatabaseCount('media', 1);
    }

    public function test_deleting_mobile_app_preserves_build_artifact_media(): void
    {
        Storage::fake(config('media-library.disk_name'));

        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
        ]);
        $artifact = Artifact::factory()->for($build)->create(['team_id' => $team->id]);
        $artifact->addMedia(UploadedFile::fake()->create('release.apk', 8))->toMediaCollection('file');

        $media = $artifact->getFirstMedia('file');
        $path = $media->getPath();
        $this->assertFileExists($path);

        $app->delete();

        $this->assertSoftDeleted('mobile_apps', ['id' => $app->id]);
        $this->assertDatabaseHas('build_records', ['id' => $build->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('artifacts', ['id' => $artifact->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('media', ['model_id' => $artifact->id]);
        $this->assertFileExists($path);
    }
}
