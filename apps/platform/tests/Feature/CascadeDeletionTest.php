<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Profile;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CascadeDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_team_deletes_its_mobile_apps_and_credentials(): void
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $credential = Credential::factory()->create(['team_id' => $team->id]);

        $team->delete();

        $this->assertDatabaseMissing('mobile_apps', ['id' => $app->id]);
        $this->assertDatabaseMissing('credentials', ['id' => $credential->id]);
    }

    public function test_deleting_mobile_app_deletes_identifiers_and_profiles(): void
    {
        $app = MobileApp::factory()->create();
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $profile = Profile::factory()->create(['app_identifier_id' => $identifier->id]);

        $app->delete();

        $this->assertDatabaseMissing('app_identifiers', ['id' => $identifier->id]);
        $this->assertDatabaseMissing('profiles', ['id' => $profile->id]);
    }

    public function test_deleting_identifier_detaches_shared_credential_without_deleting_it(): void
    {
        $credential = Credential::factory()->create();
        $identifier = AppIdentifier::factory()->create();
        $credential->appIdentifiers()->attach($identifier);
        $this->assertDatabaseCount('credentialables', 1);

        $identifier->delete();

        $this->assertDatabaseCount('credentialables', 0);
        $this->assertDatabaseHas('credentials', ['id' => $credential->id]);
    }

    public function test_deleting_profile_detaches_its_credentials(): void
    {
        $credential = Credential::factory()->create(['type' => CredentialType::ProvisioningProfile]);
        $profile = Profile::factory()->create();
        $credential->profiles()->attach($profile);
        $this->assertDatabaseCount('credentialables', 1);

        $profile->delete();

        $this->assertDatabaseCount('credentialables', 0);
        $this->assertDatabaseHas('credentials', ['id' => $credential->id]);
    }

    public function test_deleting_credential_purges_its_media_file(): void
    {
        Storage::fake('public');
        $credential = Credential::factory()->create();
        $credential->addMediaFromString('cert-bytes')->usingFileName('cert.p12')->toMediaCollection('file');
        $this->assertDatabaseCount('media', 1);

        $credential->delete();

        $this->assertDatabaseCount('media', 0);
    }

    public function test_deleting_team_purges_credential_media_via_cascade(): void
    {
        Storage::fake('public');
        $team = Team::factory()->create();
        $credential = Credential::factory()->create(['team_id' => $team->id]);
        $credential->addMediaFromString('key-bytes')->usingFileName('key.p8')->toMediaCollection('file');
        $this->assertDatabaseCount('media', 1);

        $team->delete();

        $this->assertDatabaseCount('media', 0);
    }
}
