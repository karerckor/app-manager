<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\VaultProvider;
use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialDashboardOnlyProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes are resolved once, when the application boots, so the profile
     * must be in the environment before parent::setUp() runs. Setting
     * config() inside a test method is too late.
     */
    protected function setUp(): void
    {
        putenv('CREDENTIALS_API_PROFILE=dashboard_only');
        $_ENV['CREDENTIALS_API_PROFILE'] = 'dashboard_only';
        $_SERVER['CREDENTIALS_API_PROFILE'] = 'dashboard_only';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('CREDENTIALS_API_PROFILE');
        unset($_ENV['CREDENTIALS_API_PROFILE'], $_SERVER['CREDENTIALS_API_PROFILE']);

        parent::tearDown();
    }

    public function test_secret_material_endpoints_are_absent(): void
    {
        [$user, $team, $app, $credential] = $this->workspace();
        $this->authenticate($user, $team, [
            ApiKeyScope::CredentialsRead,
            ApiKeyScope::CredentialsWrite,
            ApiKeyScope::CredentialsReveal,
        ]);

        $base = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}";

        $this->postJson("{$base}/reveal")->assertNotFound();
        $this->getJson("{$base}/file")->assertNotFound();
        $this->postJson("{$base}/file")->assertNotFound();
        $this->deleteJson("{$base}/file")->assertNotFound();
    }

    public function test_metadata_endpoints_still_work(): void
    {
        [$user, $team, $app, $credential] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsRead]);

        $this->getJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials")
            ->assertOk()
            ->assertJsonPath('data.0.vault.resource', 'arn:aws:secretsmanager:eu:1:secret:apns');
    }

    public function test_verification_still_works(): void
    {
        [$user, $team, $app, $credential] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson(
            "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}/verification",
            ['status' => 'verified'],
        )->assertOk();
    }

    public function test_the_dashboard_can_still_download(): void
    {
        [$user, $team] = $this->workspace();
        $local = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
        ]);

        // No media attached, so 404 is the expected body-level answer — the
        // point of this assertion is that the route still exists on the web
        // side rather than 405/RouteNotFound.
        $this->actingAs($user)
            ->get(route('accounts.credentials.download', [$team, $local]))
            ->assertNotFound();

        $this->assertTrue(
            app('router')->has('accounts.credentials.download'),
            'The dashboard download route must survive the dashboard-only profile.',
        );
    }

    public function test_credential_webhook_events_are_not_recorded(): void
    {
        [$user, $team, $app, $credential] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify]);

        $this->postJson(
            "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}/verification",
            ['status' => 'failed', 'error' => 'boom'],
        )->assertOk();

        $this->assertSame(0, WebhookEvent::query()
            ->where('event_type', WebhookEventType::CredentialVerificationFailed)
            ->count());
    }

    public function test_credential_events_are_not_subscribable(): void
    {
        $this->assertNotContains(
            WebhookEventType::CredentialExpired->value,
            WebhookEventType::subscribableValues(),
        );
        $this->assertContains(
            WebhookEventType::BuildCompleted->value,
            WebhookEventType::subscribableValues(),
        );
    }

    public function test_the_reveal_scope_cannot_be_granted_to_a_new_api_key(): void
    {
        [$user, $team] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::ApiKeysWrite, ApiKeyScope::CredentialsRead]);

        $this->postJson("/api/v1/{$team->slug}/api-keys", [
            'name' => 'Runner key',
            'scopes' => [ApiKeyScope::CredentialsReveal->value],
        ])->assertUnprocessable()->assertJsonValidationErrors('scopes.0');

        $this->postJson("/api/v1/{$team->slug}/api-keys", [
            'name' => 'Runner key',
            'scopes' => [ApiKeyScope::CredentialsRead->value],
        ])->assertCreated();

        $credentialsGroup = collect(ApiKeyScope::groups())->firstWhere('key', 'credentials');
        $this->assertNotContains(
            ApiKeyScope::CredentialsReveal->value,
            array_column($credentialsGroup['scopes'], 'value'),
        );
    }

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: Credential} */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, 'owner');
        $user->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = AppIdentifier::factory()->create([
            'mobile_app_id' => $app->id,
            'platform' => Platform::Ios,
        ]);
        $credential = Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, 'arn:aws:secretsmanager:eu:1:secret:apns')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $identifier->credentials()->attach($credential);

        return [$user->fresh(), $team, $app, $credential];
    }

    /** @param list<ApiKeyScope> $scopes */
    private function authenticate(User $user, Team $team, array $scopes): void
    {
        $token = $user->createTeamApiKey(
            $team,
            'Runner',
            array_map(fn (ApiKeyScope $scope): string => $scope->value, $scopes),
        )->plainTextToken;
        $this->app['auth']->guard('sanctum')->forgetUser();
        $this->withToken($token);
    }
}
