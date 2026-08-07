<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoApiKeyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private MobileApp $mobileApp;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('media-library.disk_name'));
        $this->seed(DemoSeeder::class);

        $this->user = User::where('email', 'demo@example.com')->firstOrFail();
        $this->team = $this->user->currentTeam;
        $this->mobileApp = $this->team->mobileApps()->where('slug', 'example-app')->firstOrFail();

        $this->withHeaders([
            'Authorization' => 'Bearer '.DemoSeeder::API_KEY,
            'Accept' => 'application/json',
        ]);
    }

    public function test_seeded_api_key_authenticates_without_a_web_session(): void
    {
        $this->assertGuest();

        $this->getJson($this->api('/apps'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['slug' => 'example-app'])
            ->assertJsonFragment(['slug' => 'example-app-blue']);
    }

    public function test_seeded_api_exposes_android_and_ios_signing_materials(): void
    {
        $this->getJson($this->api('/apps/example-app/identifiers'))
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonFragment(['platform' => 'android', 'identifier' => 'com.example.app'])
            ->assertJsonFragment(['platform' => 'android', 'identifier' => 'com.example.app.beta'])
            ->assertJsonFragment(['platform' => 'ios', 'identifier' => 'com.example.app'])
            ->assertJsonFragment(['platform' => 'web', 'identifier' => 'com.example.app.web']);

        $response = $this->getJson($this->api('/apps/example-app/credentials'))
            ->assertOk()
            ->assertJsonCount(9, 'data')
            ->assertJsonFragment(['type' => CredentialType::AndroidKeystore->value])
            ->assertJsonFragment(['type' => CredentialType::FirebaseConfig->value])
            ->assertJsonFragment(['type' => CredentialType::IosCertificate->value])
            ->assertJsonFragment(['type' => CredentialType::ProvisioningProfile->value])
            ->assertJsonFragment(['type' => CredentialType::GooglePlayServiceAccount->value])
            ->assertJsonFragment(['type' => CredentialType::AppleApiKey->value])
            ->assertJsonFragment(['type' => CredentialType::GoogleServicesJson->value])
            ->assertJsonFragment(['type' => CredentialType::GoogleServiceInfoPlist->value]);

        $expectedFiles = [
            [CredentialType::AndroidKeystore->value, ProfileType::Production->value, 'production.keystore'],
            [CredentialType::IosCertificate->value, ProfileType::AppStore->value, 'distribution.p12'],
            [CredentialType::ProvisioningProfile->value, ProfileType::AppStore->value, 'appstore.mobileprovision'],
        ];

        foreach ($expectedFiles as [$type, $profileType, $fileName]) {
            $credential = collect($response->json('data'))->first(fn ($candidate) => $candidate['type'] === $type
                && collect($candidate['profiles'])->contains('type', $profileType)
            );
            $this->assertNotNull($credential);
            $this->get($this->api("/apps/example-app/credentials/{$credential['id']}/file"))
                ->assertOk()
                ->assertDownload($fileName);
        }
    }

    public function test_invalid_api_key_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer invalid-api-key')
            ->getJson($this->api('/apps'))
            ->assertUnauthorized();
    }

    public function test_seeded_api_key_cannot_access_another_team(): void
    {
        $foreignTeam = Team::factory()->create();

        $this->getJson("/api/v1/{$foreignTeam->slug}/apps")
            ->assertForbidden();
    }

    public function test_seeded_api_key_can_manage_apps_and_identifiers(): void
    {
        $created = $this->postJson($this->api('/apps'), [
            'name' => 'API Managed App',
            'description' => 'Created with the seeded API key.',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'api-managed-app');

        $appId = $created->json('data.id');
        $this->assertDatabaseHas('mobile_apps', [
            'id' => $appId,
            'team_id' => $this->team->id,
        ]);

        $identifier = $this->postJson($this->api('/apps/api-managed-app/identifiers'), [
            'platform' => 'ios',
            'identifier' => 'com.example.api-managed',
            'name' => 'App Store',
        ])->assertCreated()
            ->assertJsonPath('data.platform', 'ios');

        $identifierId = $identifier->json('data.id');
        $this->patchJson($this->api("/apps/api-managed-app/identifiers/{$identifierId}"), [
            'name' => 'Production',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Production');

        $this->deleteJson($this->api('/apps/api-managed-app'))
            ->assertNoContent();
        $this->assertSoftDeleted('mobile_apps', ['id' => $appId]);
    }

    public function test_seeded_api_key_can_manage_assets_and_credentials(): void
    {
        $assetUrl = $this->api('/apps/example-app/assets/icon');
        $this->post($assetUrl, [
            'file' => UploadedFile::fake()->image('icon.png', 1024, 1024),
        ])->assertOk()
            ->assertJsonPath('data.assets.icon.name', 'icon.png');

        $this->get($assetUrl)
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $identifier = $this->mobileApp->appIdentifiers()
            ->where('platform', Platform::Android)
            ->firstOrFail();
        $credentialsUrl = $this->api('/apps/example-app/credentials');
        $created = $this->post($credentialsUrl, [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::AndroidKeystore->value,
            'name' => 'API Keystore',
            'profile_type' => ProfileType::Preview->value,
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'api-release',
                'key_password' => 'key-secret',
            ],
            'file' => UploadedFile::fake()->createWithContent('api-release.jks', 'keystore-data'),
        ])->assertCreated()
            ->assertJsonPath('data.name', 'API Keystore')
            ->assertJsonPath('data.profiles.0.type', 'preview')
            ->assertJsonPath('data.file.name', 'api-release.jks');

        $credentialId = $created->json('data.id');
        $this->getJson($credentialsUrl)
            ->assertOk()
            ->assertJsonFragment(['name' => 'API Keystore']);

        $this->get("{$credentialsUrl}/{$credentialId}/file")
            ->assertOk()
            ->assertDownload('api-release.jks');

        $this->deleteJson("{$credentialsUrl}/{$credentialId}")
            ->assertNoContent();
        $this->deleteJson($assetUrl)
            ->assertNoContent();
    }

    private function api(string $path): string
    {
        return "/api/v1/{$this->team->slug}{$path}";
    }
}
