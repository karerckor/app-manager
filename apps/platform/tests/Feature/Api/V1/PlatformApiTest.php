<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_is_required(): void
    {
        $team = Team::factory()->create();

        $this->getJson("/api/v1/{$team->slug}/apps")
            ->assertUnauthorized();
    }

    public function test_api_key_cannot_access_a_team_the_user_does_not_belong_to(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $this->withToken($user->createToken('CI')->plainTextToken)
            ->getJson("/api/v1/{$team->slug}/apps")
            ->assertForbidden();
    }

    public function test_mobile_apps_can_be_managed_and_are_scoped_to_the_team_slug(): void
    {
        [$user, $team] = $this->teamMember();
        $foreignApp = MobileApp::factory()->create();
        $this->authenticate($user);

        $created = $this->postJson("/api/v1/{$team->slug}/apps", [
            'name' => 'Customer App',
            'description' => 'Public mobile client',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'customer-app')
            ->assertJsonPath('data.assets.icon', null);

        $appId = $created->json('data.id');
        $this->assertDatabaseHas('mobile_apps', [
            'id' => $appId,
            'team_id' => $team->id,
        ]);

        $this->patchJson("/api/v1/{$team->slug}/apps/customer-app", [
            'name' => 'Customer App 2',
            'slug' => 'customer-app-2',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Customer App 2');

        $this->getJson("/api/v1/{$team->slug}/apps/{$foreignApp->slug}")
            ->assertNotFound();

        $this->deleteJson("/api/v1/{$team->slug}/apps/customer-app-2")
            ->assertNoContent();
        $this->assertDatabaseMissing('mobile_apps', ['id' => $appId]);
    }

    public function test_identifiers_can_be_managed_but_not_cross_scoped_between_apps(): void
    {
        [$user, $team] = $this->teamMember();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $foreignIdentifier = AppIdentifier::factory()->create();
        $this->authenticate($user);

        $created = $this->postJson("/api/v1/{$team->slug}/apps/{$app->slug}/identifiers", [
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.customer',
            'name' => 'Production',
        ])->assertCreated()
            ->assertJsonPath('data.platform', 'ios');

        $id = $created->json('data.id');
        $this->patchJson("/api/v1/{$team->slug}/apps/{$app->slug}/identifiers/{$id}", [
            'name' => 'App Store',
        ])->assertOk()
            ->assertJsonPath('data.name', 'App Store');

        $this->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/identifiers/{$foreignIdentifier->id}")
            ->assertNotFound();

        $this->deleteJson("/api/v1/{$team->slug}/apps/{$app->slug}/identifiers/{$id}")
            ->assertNoContent();
    }

    public function test_identifier_versions_are_exposed_and_version_code_is_android_only(): void
    {
        [$user, $team] = $this->teamMember();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->authenticate($user);
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/identifiers";

        $created = $this->postJson($url, [
            'platform' => Platform::Android->value,
            'identifier' => 'com.example.customer',
            'version' => '2.3.0',
            'version_code' => 20300,
        ])->assertCreated()
            ->assertJsonPath('data.version', '2.3.0')
            ->assertJsonPath('data.version_code', 20300);

        $this->patchJson("{$url}/{$created->json('data.id')}", [
            'platform' => Platform::Ios->value,
        ])->assertOk()
            ->assertJsonPath('data.platform', Platform::Ios->value)
            ->assertJsonPath('data.version_code', null);

        $this->postJson($url, [
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.customer.ios',
            'version' => '2.3.0',
            'version_code' => 20300,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('version_code');
    }

    public function test_ios_build_number_is_exposed_and_is_ios_only(): void
    {
        [$user, $team] = $this->teamMember();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->authenticate($user);
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/identifiers";

        $created = $this->postJson($url, [
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.customer',
            'version' => '2.3.0',
            'build_number' => '20300',
        ])->assertCreated()
            ->assertJsonPath('data.version', '2.3.0')
            ->assertJsonPath('data.build_number', '20300')
            ->assertJsonPath('data.version_code', null);

        $id = $created->json('data.id');

        $this->getJson("{$url}/{$id}")
            ->assertOk()
            ->assertJsonPath('data.build_number', '20300');

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.build_number', '20300');

        $this->postJson($url, [
            'platform' => Platform::Android->value,
            'identifier' => 'com.example.customer.android',
            'build_number' => '20300',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('build_number');
    }

    public function test_first_identifier_is_default_and_default_can_be_moved_explicitly(): void
    {
        [$user, $team] = $this->teamMember();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->authenticate($user);
        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/identifiers";

        $first = $this->postJson($url, [
            'platform' => Platform::Android->value,
            'identifier' => 'com.example.customer',
            'name' => 'Production',
        ])->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->json('data');

        $second = $this->postJson($url, [
            'platform' => Platform::Android->value,
            'identifier' => 'com.example.customer.beta',
            'name' => 'Beta',
        ])->assertCreated()
            ->assertJsonPath('data.is_default', false)
            ->json('data');

        $this->patchJson("{$url}/{$second['id']}", [
            'is_default' => true,
        ])->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->getJson("{$url}/{$first['id']}")
            ->assertOk()
            ->assertJsonPath('data.is_default', false);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.id', $second['id'])
            ->assertJsonPath('data.0.is_default', true);
    }

    public function test_icon_and_splashscreen_can_be_uploaded_read_and_deleted(): void
    {
        Storage::fake('public');
        [$user, $team] = $this->teamMember();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->authenticate($user);

        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/assets/icon";
        $this->post($url, [
            'file' => UploadedFile::fake()->image('icon.png', 1024, 1024),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.assets.icon.name', 'icon.png');

        $this->get($url, ['Authorization' => 'Bearer '.$this->token])
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->deleteJson($url)->assertNoContent();
        $this->get($url, ['Authorization' => 'Bearer '.$this->token])
            ->assertNotFound();
    }

    public function test_credentials_can_be_managed_with_encrypted_payload_and_file(): void
    {
        Storage::fake('public');
        [$user, $team] = $this->teamMember();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
        ]);
        $this->authenticate($user);

        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials";
        $created = $this->post($url, [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::AndroidKeystore->value,
            'name' => 'Release keystore',
            'profile_type' => ProfileType::Production->value,
            'payload' => [
                'store_password' => 'store-secret',
                'key_alias' => 'release',
                'key_password' => 'key-secret',
            ],
            'file' => UploadedFile::fake()->createWithContent('release.jks', 'keystore-data'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'android_keystore')
            ->assertJsonPath('data.profiles.0.type', 'production')
            ->assertJsonPath('data.payload.key_alias', 'release')
            ->assertJsonPath('data.file.name', 'release.jks');

        $id = $created->json('data.id');
        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->patchJson("{$url}/{$id}", [
            'name' => 'Release keystore 2026',
            'expires_at' => '2027-07-14T00:00:00Z',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Release keystore 2026');

        $this->get("{$url}/{$id}/file", ['Authorization' => 'Bearer '.$this->token])
            ->assertOk()
            ->assertDownload('release.jks');

        $foreignApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->getJson("/api/v1/{$team->slug}/apps/{$foreignApp->slug}/credentials/{$id}")
            ->assertNotFound();

        $this->deleteJson("{$url}/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('credentials', ['id' => $id]);
    }

    public function test_credential_types_expose_the_validation_contract(): void
    {
        [$user, $team] = $this->teamMember();
        $this->authenticate($user);

        $this->getJson("/api/v1/{$team->slug}/credential-types")
            ->assertOk()
            ->assertJsonFragment(['value' => 'android_keystore'])
            ->assertJsonFragment(['value' => 'provisioning_profile'])
            ->assertJsonFragment([
                'value' => 'preview',
                'platform' => 'android',
                'artifact' => '.apk',
            ]);
    }

    public function test_identifier_scoped_credential_is_listed_with_identifier_and_file(): void
    {
        Storage::fake('public');
        [$user, $team] = $this->teamMember();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.app',
        ]);
        $this->authenticate($user);

        $valid = json_encode([
            'project_info' => ['project_id' => 'demo'],
            'client' => [['client_info' => ['mobilesdk_app_id' => '1:1:android:a']]],
        ], JSON_THROW_ON_ERROR);

        $url = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials";
        $this->post($url, [
            'app_identifier_id' => $identifier->id,
            'type' => CredentialType::GoogleServicesJson->value,
            'name' => 'Android Firebase',
            'file' => UploadedFile::fake()->createWithContent('google-services.json', $valid),
        ], ['Accept' => 'application/json'])
            ->assertCreated();

        $response = $this->getJson($url)->assertOk();

        $credential = collect($response->json('data'))
            ->firstWhere('type', CredentialType::GoogleServicesJson->value);

        $this->assertNotNull($credential);
        $this->assertSame('com.example.app', $credential['app_identifiers'][0]['identifier']);
        $this->assertNotNull($credential['file']['url']);

        $this->get($credential['file']['url'], ['Authorization' => 'Bearer '.$this->token])
            ->assertOk();
    }

    private string $token;

    private function teamMember(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, 'owner');

        return [$user, $team];
    }

    private function authenticate(User $user): void
    {
        $this->token = $user->createToken('CI')->plainTextToken;
        $this->withToken($this->token);
    }
}
