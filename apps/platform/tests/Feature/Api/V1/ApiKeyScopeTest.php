<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApiKeyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_application_features_have_independent_read_and_write_scopes(): void
    {
        $this->assertSame([
            'apps:read',
            'apps:write',
            'blueprints:read',
            'blueprints:write',
            'identifiers:read',
            'identifiers:write',
            'assets:read',
            'assets:write',
            'files:read',
            'files:write',
            'credentials:read',
            'credentials:reveal',
            'credentials:verify',
            'credentials:write',
            'builds:read',
            'builds:write',
            'logs:read',
            'logs:write',
            'artifacts:read',
            'artifacts:write',
            'integrations:read',
            'integrations:write',
            'integrations:proxy',
            'pipelines:read',
            'pipelines:write',
            'pipelines:run',
            'webhooks:read',
            'webhooks:write',
            'deliveries:read',
            'api-keys:read',
            'api-keys:write',
        ], ApiKeyScope::values());
    }

    public function test_app_scopes_are_enforced_per_http_operation(): void
    {
        [$user, $team] = $this->application();

        $this->authenticate($user, $team, ApiKeyScope::AppsRead);
        $this->getJson($this->api($team, '/apps'))->assertOk();
        $this->postJson($this->api($team, '/apps'), ['name' => 'Forbidden'])->assertForbidden();

        $this->authenticate($user, $team, ApiKeyScope::AppsWrite);
        $this->postJson($this->api($team, '/apps'), ['name' => 'Created by writer'])->assertCreated();
        $this->getJson($this->api($team, '/apps'))->assertForbidden();
    }

    public function test_identifier_scopes_are_enforced_per_http_operation(): void
    {
        [$user, $team, $app] = $this->application();
        $url = $this->api($team, "/apps/{$app->slug}/identifiers");

        $this->authenticate($user, $team, ApiKeyScope::IdentifiersRead);
        $this->getJson($url)->assertOk();
        $this->postJson($url, [
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.forbidden',
        ])->assertForbidden();

        $this->authenticate($user, $team, ApiKeyScope::IdentifiersWrite);
        $this->postJson($url, [
            'platform' => Platform::Ios->value,
            'identifier' => 'com.example.writer',
        ])->assertCreated();
        $this->getJson($url)->assertForbidden();
    }

    public function test_asset_scopes_are_enforced_per_http_operation(): void
    {
        Storage::fake(config('media-library.disk_name'));
        [$user, $team, $app] = $this->application();
        $url = $this->api($team, "/apps/{$app->slug}/assets/icon");
        $filesUrl = $this->api($team, "/apps/{$app->slug}/assets");

        $this->authenticate($user, $team, ApiKeyScope::AssetsRead);
        $this->getJson($filesUrl)->assertOk();
        $this->get($url, ['Authorization' => 'Bearer '.$this->token])->assertNotFound();
        $this->post($url, [
            'file' => UploadedFile::fake()->image('forbidden.png', 128, 128),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->authenticate($user, $team, ApiKeyScope::AssetsWrite);
        $this->post($url, [
            'file' => UploadedFile::fake()->image('icon.png', 1024, 1024),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->getJson($filesUrl)->assertForbidden();
        $this->get($url, ['Authorization' => 'Bearer '.$this->token])->assertForbidden();
    }

    public function test_credential_scopes_are_enforced_per_http_operation(): void
    {
        [$user, $team, $app] = $this->application();
        $url = $this->api($team, "/apps/{$app->slug}/credentials");
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.secrets',
        ]);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::IosCertificate,
            'payload' => ['password' => 'certificate-secret'],
        ]);
        $identifier->credentials()->attach($credential);

        $this->authenticate($user, $team, ApiKeyScope::CredentialsRead);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonMissingPath('data.0.payload');
        $this->postJson("{$url}/{$credential->id}/reveal")->assertForbidden();
        $this->postJson($url, [])->assertForbidden();

        $this->authenticate($user, $team, ApiKeyScope::CredentialsReveal);
        $this->postJson("{$url}/{$credential->id}/reveal")
            ->assertOk()
            ->assertJsonPath('data.payload.password', 'certificate-secret');
        $this->getJson($url)->assertForbidden();

        $this->authenticate($user, $team, ApiKeyScope::CredentialsWrite);
        $this->postJson($url, [])->assertUnprocessable();
        $this->getJson($url)->assertForbidden();

        $viewer = User::factory()->create();
        $team->addUser($viewer, 'viewer');
        $this->authenticate($viewer, $team, ApiKeyScope::CredentialsReveal);
        $this->postJson("{$url}/{$credential->id}/reveal")->assertForbidden();
    }

    private string $token;

    /**
     * @return array{User, Team, MobileApp}
     */
    private function application(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);

        return [$user, $team, $app];
    }

    private function authenticate(User $user, Team $team, ApiKeyScope $scope): void
    {
        $this->token = $user->createTeamApiKey($team, $scope->value, [$scope->value])->plainTextToken;
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($this->token);
    }

    private function api(Team $team, string $path): string
    {
        return "/api/v1/{$team->slug}{$path}";
    }
}
