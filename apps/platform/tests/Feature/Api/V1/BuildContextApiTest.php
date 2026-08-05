<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuildContextApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_is_required(): void
    {
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        $this->getJson($this->url($team, $app, Platform::Android, ProfileType::Production))
            ->assertUnauthorized();
    }

    public function test_team_membership_is_required(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $this->authenticate($user);

        $this->getJson($this->url($team, $app, Platform::Android, ProfileType::Production))
            ->assertForbidden();
    }

    public function test_platform_and_profile_type_are_required(): void
    {
        [$user, $team, $app] = $this->application();
        $this->authenticate($user);

        $this->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/build-context")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform', 'profile_type']);
    }

    public function test_default_target_returns_complete_scoped_build_context(): void
    {
        Storage::fake('public');
        [$user, $team, $app] = $this->application();
        $target = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.app',
            'name' => 'Production',
            'version' => '2.3.0',
            'version_code' => 20300,
            'is_default' => true,
        ]);
        $beta = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.app.beta',
            'name' => 'Beta',
            'is_default' => false,
        ]);
        $profile = $target->profiles()->create([
            'type' => ProfileType::Production,
            'name' => 'Production',
        ]);
        $betaProfile = $beta->profiles()->create([
            'type' => ProfileType::Preview,
            'name' => 'Preview',
        ]);

        $keystore = $this->credential($team, CredentialType::AndroidKeystore, 'Production keystore', [
            'store_password' => 'store-secret',
            'key_alias' => 'release',
            'key_password' => 'key-secret',
        ], 'production.jks');
        $firebase = $this->credential(
            $team,
            CredentialType::GoogleServicesJson,
            'Firebase client',
            null,
            'google-services.json',
        );
        $preview = $this->credential(
            $team,
            CredentialType::AndroidKeystore,
            'Preview keystore',
            ['store_password' => 'preview', 'key_alias' => 'preview', 'key_password' => 'preview'],
            'preview.jks',
        );
        $profile->credentials()->attach($keystore);
        $target->credentials()->attach($firebase);
        $betaProfile->credentials()->attach($preview);
        $foreignApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $foreignIdentifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $foreignApp->id,
            'platform' => Platform::Android,
        ]);
        $foreignCredential = $this->credential(
            $team,
            CredentialType::GooglePlayServiceAccount,
            'Foreign Play credential',
            null,
            'foreign-play.json',
        );
        $foreignIdentifier->credentials()->attach($foreignCredential);

        $app->addMediaFromString('icon-bytes')->usingFileName('icon.png')->toMediaCollection('app_icon');
        $this->authenticate($user);

        $response = $this->getJson($this->url($team, $app, Platform::Android, ProfileType::Production))
            ->assertOk()
            ->assertJsonPath('data.schema_version', 1)
            ->assertJsonPath('data.app.id', $app->id)
            ->assertJsonPath('data.app.name', $app->name)
            ->assertJsonPath('data.target.id', $target->id)
            ->assertJsonPath('data.target.identifier', 'com.example.app')
            ->assertJsonPath('data.target.version', '2.3.0')
            ->assertJsonPath('data.target.version_code', 20300)
            ->assertJsonPath('data.target.is_default', true)
            ->assertJsonPath('data.profile.value', 'production')
            ->assertJsonPath('data.profile.artifact', '.aab')
            ->assertJsonPath('data.profile.id', $profile->id)
            ->assertJsonPath('data.assets.icon.name', 'icon.png')
            ->assertJsonCount(2, 'data.credentials')
            ->assertJsonPath('data.credentials.0.id', $keystore->id)
            ->assertJsonPath('data.credentials.0.file.name', 'production.jks')
            ->assertJsonPath('data.credentials.0.profiles.0.id', $profile->id)
            ->assertJsonCount(1, 'data.credentials.0.profiles')
            ->assertJsonPath('data.credentials.0.file.url', route(
                'api.v1.apps.credentials.file.show',
                ['team' => $team, 'mobileApp' => $app, 'credential' => $keystore],
            ))
            ->assertJsonPath('data.credentials.1.id', $firebase->id)
            ->assertJsonPath('data.credentials.1.app_identifiers.0.id', $target->id)
            ->assertJsonCount(1, 'data.credentials.1.app_identifiers')
            ->assertJsonMissing(['id' => $preview->id])
            ->assertJsonMissing(['id' => $foreignCredential->id]);

        $fixture = json_decode(
            file_get_contents(base_path('../../contracts/api-v1/build-context-v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(
            $this->contractKeyPaths($fixture['data']),
            $this->contractKeyPaths($response->json('data')),
        );
    }

    public function test_explicit_identifier_overrides_the_default(): void
    {
        [$user, $team, $app] = $this->application();
        AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.app',
            'is_default' => true,
        ]);
        $beta = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'identifier' => 'com.example.app.beta',
            'is_default' => false,
        ]);
        $this->authenticate($user);

        $this->getJson($this->url(
            $team,
            $app,
            Platform::Android,
            ProfileType::Preview,
            $beta->identifier,
        ))->assertOk()
            ->assertJsonPath('data.target.id', $beta->id)
            ->assertJsonPath('data.profile.value', 'preview')
            ->assertJsonPath('data.profile.id', null);
    }

    public function test_a_single_target_is_used_without_a_declared_default(): void
    {
        [$user, $team, $app] = $this->application();
        $target = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
            'identifier' => 'com.example.app',
            'build_number' => '42',
            'is_default' => false,
        ]);
        $this->authenticate($user);

        $this->getJson($this->url($team, $app, Platform::Ios, ProfileType::AppStore))
            ->assertOk()
            ->assertJsonPath('data.target.id', $target->id)
            ->assertJsonPath('data.target.build_number', '42');
    }

    public function test_multiple_targets_without_a_default_are_ambiguous(): void
    {
        [$user, $team, $app] = $this->application();
        AppIdentifier::factory()->count(2)->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'is_default' => false,
        ]);
        $this->authenticate($user);

        $this->getJson($this->url($team, $app, Platform::Android, ProfileType::Production))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('identifier');
    }

    public function test_profile_type_must_match_the_platform(): void
    {
        [$user, $team, $app] = $this->application();
        AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Android,
            'is_default' => true,
        ]);
        $this->authenticate($user);

        $this->getJson($this->url($team, $app, Platform::Android, ProfileType::AppStore))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile_type');
    }

    public function test_identifier_from_another_app_is_not_revealed(): void
    {
        [$user, $team, $app] = $this->application();
        $foreign = AppIdentifier::factory()->create([
            'platform' => Platform::Android,
            'identifier' => 'com.example.foreign',
        ]);
        $this->authenticate($user);

        $this->getJson($this->url(
            $team,
            $app,
            Platform::Android,
            ProfileType::Production,
            $foreign->identifier,
        ))->assertNotFound();
    }

    private function application(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, 'owner');
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'name' => 'Example App',
            'slug' => 'example-app',
        ]);

        return [$user, $team, $app];
    }

    private function authenticate(User $user): void
    {
        $this->withToken($user->createToken('CI')->plainTextToken);
    }

    private function credential(
        Team $team,
        CredentialType $type,
        string $name,
        ?array $payload,
        string $fileName,
    ): Credential {
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => $type,
            'name' => $name,
            'payload' => $payload,
        ]);
        $credential->addMediaFromString($name.'-bytes')
            ->usingFileName($fileName)
            ->toMediaCollection('file');

        return $credential;
    }

    private function url(
        Team $team,
        MobileApp $app,
        Platform $platform,
        ProfileType $profile,
        ?string $identifier = null,
    ): string {
        $query = http_build_query(array_filter([
            'platform' => $platform->value,
            'profile_type' => $profile->value,
            'identifier' => $identifier,
        ], fn ($value) => $value !== null));

        return "/api/v1/{$team->slug}/apps/{$app->slug}/build-context?{$query}";
    }

    private function contractKeyPaths(array $value, string $prefix = ''): array
    {
        $paths = [];

        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $paths[] = $path;

            if (! is_array($item) || $item === []) {
                continue;
            }

            if (str_ends_with($path, '.payload')) {
                continue;
            }

            if (array_is_list($item)) {
                if (is_array($item[0] ?? null)) {
                    $paths = [...$paths, ...$this->contractKeyPaths($item[0], $path.'.*')];
                }

                continue;
            }

            $paths = [...$paths, ...$this->contractKeyPaths($item, $path)];
        }

        sort($paths);

        return $paths;
    }
}
