<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\CredentialType;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\Platform;
use App\Enums\VaultProvider;
use App\Enums\WebhookEventType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CredentialDashboardOnlyProfileTest extends TestCase
{
    use RefreshDatabase;

    /** The environment's prior value, so tearDown() restores it rather than merely unsetting it. `false` means it was not set. */
    private string|false $priorEnvValue;

    /**
     * Routes are resolved once, when the application boots, so the profile
     * must be in the environment before parent::setUp() runs. Setting
     * config() inside a test method is too late.
     */
    protected function setUp(): void
    {
        $this->priorEnvValue = getenv('CREDENTIALS_API_PROFILE');

        putenv('CREDENTIALS_API_PROFILE=dashboard_only');
        $_ENV['CREDENTIALS_API_PROFILE'] = 'dashboard_only';
        $_SERVER['CREDENTIALS_API_PROFILE'] = 'dashboard_only';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->priorEnvValue === false) {
            putenv('CREDENTIALS_API_PROFILE');
            unset($_ENV['CREDENTIALS_API_PROFILE'], $_SERVER['CREDENTIALS_API_PROFILE']);
        } else {
            putenv("CREDENTIALS_API_PROFILE={$this->priorEnvValue}");
            $_ENV['CREDENTIALS_API_PROFILE'] = $this->priorEnvValue;
            $_SERVER['CREDENTIALS_API_PROFILE'] = $this->priorEnvValue;
        }

        parent::tearDown();
    }

    public function test_secret_material_endpoints_are_absent(): void
    {
        [$user, $team, $app, $credential, $identifier] = $this->workspace();
        $this->authenticate($user, $team, [
            ApiKeyScope::CredentialsRead,
            ApiKeyScope::CredentialsWrite,
            ApiKeyScope::CredentialsReveal,
            ApiKeyScope::IdentifiersWrite,
            ApiKeyScope::IntegrationsWrite,
        ]);

        $base = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}";

        $this->postJson("{$base}/reveal")->assertNotFound();
        $this->getJson("{$base}/file")->assertNotFound();
        $this->postJson("{$base}/file")->assertNotFound();
        $this->deleteJson("{$base}/file")->assertNotFound();

        // These two are the routes this task moved rather than newly wrote,
        // which makes them the likely conflict site in a future rebase.
        // Assert them explicitly by POSTing, so the assertion survives a
        // route rename rather than depending on the current route name.
        $this->postJson("/api/v1/{$team->slug}/apps/{$app->slug}/expo/identifiers/{$identifier->id}/provision")
            ->assertNotFound();
        $this->postJson("/api/v1/{$team->slug}/apps/{$app->slug}/expo/identifiers/{$identifier->id}/credentials/{$credential->id}")
            ->assertNotFound();
    }

    public function test_local_secret_material_cannot_enter_through_create_or_update(): void
    {
        [$user, $team, $app] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsWrite]);
        $identifier = $app->appIdentifiers()->firstOrFail();

        $this->postJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials", [
            'app_identifier_id' => $identifier->id,
            'name' => 'Local API credential',
            'type' => CredentialType::ApnsKey->value,
            'storage_mode' => 'local',
            'payload' => ['key_id' => 'SECRET'],
        ])->assertUnprocessable()->assertJsonValidationErrors('storage_mode');

        $this->postJson("/api/v1/{$team->slug}/apps/{$app->slug}/credentials", [
            'app_identifier_id' => $identifier->id,
            'name' => 'External API credential',
            'type' => CredentialType::ApnsKey->value,
            'storage_mode' => 'external',
            'payload' => [
                'key_id' => 'KEYID12345',
                'team_id' => 'TEAMID1234',
            ],
            'vault' => [
                'provider' => 'aws_secrets_manager',
                'resource' => 'arn:aws:secretsmanager:eu:1:secret:apns',
                'metadata' => ['region' => 'eu-central-1'],
            ],
        ])->assertCreated()->assertJsonPath('data.storage_mode', 'external');

        $local = Credential::factory()->create([
            'team_id' => $team->id,
            'type' => CredentialType::ApnsKey,
        ]);
        $identifier->credentials()->attach($local);
        $base = "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$local->id}";

        $this->patchJson($base, [
            'payload' => ['key_id' => 'SECRET'],
        ])->assertUnprocessable()->assertJsonValidationErrors('payload');

        $this->patch($base, [
            'file' => UploadedFile::fake()->create('AuthKey.p8', 1, 'application/x-pem-file'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
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

    public function test_credential_webhook_events_are_not_recorded_while_other_events_still_are(): void
    {
        // The recorder reads these per call (they are not resolved at boot,
        // unlike the route profile), so setting them here is legitimate and
        // necessary: .env.example ships both channels disabled and there is
        // no .env.testing, so without this the test would pass vacuously on
        // a fresh clone or CI even with the credential guard deleted.
        config([
            'integrations.enabled' => true,
            'integrations.webhooks.enabled' => true,
            'integrations.notifications.enabled' => true,
        ]);

        [$user, $team, $app, $credential] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::CredentialsVerify, ApiKeyScope::IdentifiersWrite]);

        $this->postJson(
            "/api/v1/{$team->slug}/apps/{$app->slug}/credentials/{$credential->id}/verification",
            ['status' => 'failed', 'error' => 'boom'],
        )->assertOk();

        $this->assertSame(0, WebhookEvent::query()
            ->where('event_type', WebhookEventType::CredentialVerificationFailed)
            ->count());

        // Positive control: with the exact same profile and the exact same
        // explicitly-enabled channel config, a non-credential event is still
        // recorded. Without this, the zero count above cannot be told apart
        // from "nothing records at all" (e.g. because the channel flags
        // were left at their .env.example defaults).
        $this->postJson("/api/v1/{$team->slug}/apps/{$app->slug}/identifiers", [
            'platform' => Platform::Android->value,
            'identifier' => 'com.example.control',
        ])->assertCreated();

        $this->assertSame(1, WebhookEvent::query()
            ->where('event_type', WebhookEventType::AppIdentifierCreated)
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

    public function test_an_endpoint_already_subscribed_to_a_credential_event_can_still_be_updated(): void
    {
        config(['webhooks.allow_private_addresses' => true]);

        [$user, $team] = $this->workspace();
        $this->authenticate($user, $team, [ApiKeyScope::WebhooksWrite]);

        $endpoint = $this->credentialSubscribedEndpoint($team);

        // The dashboard form seeds itself from [...endpoint.events], so a
        // PATCH that only changes the URL still echoes every currently
        // stored event back — including the credential one. That echo is
        // the failure mode this reproduces: submit `events` explicitly,
        // carrying the stored credential event, exactly as the form would.
        $this->patchJson("/api/v1/{$team->slug}/webhooks/{$endpoint->id}", [
            'url' => 'https://example.test/new-hook',
            'events' => [
                WebhookEventType::CredentialExpired->value,
                WebhookEventType::BuildCompleted->value,
            ],
        ])->assertOk();

        $endpoint->refresh();
        $this->assertSame('https://example.test/new-hook', $endpoint->url);
        $this->assertContains(WebhookEventType::CredentialExpired->value, $endpoint->subscribed_events);
        $this->assertContains(WebhookEventType::BuildCompleted->value, $endpoint->subscribed_events);

        // A brand-new endpoint gets no such allowance: creation always
        // validates against what is subscribable right now.
        $this->postJson("/api/v1/{$team->slug}/webhooks", [
            'name' => 'New hook',
            'url' => 'https://example.test/new',
            'events' => [WebhookEventType::CredentialExpired->value],
        ])->assertUnprocessable()->assertJsonValidationErrors('events.0');
    }

    public function test_the_dashboard_update_route_also_preserves_a_stored_credential_event(): void
    {
        config([
            'integrations.enabled' => true,
            'integrations.webhooks.enabled' => true,
            'webhooks.allow_private_addresses' => true,
        ]);

        [$user, $team] = $this->workspace();
        $endpoint = $this->credentialSubscribedEndpoint($team);

        // Same failure mode as the API test above, reproduced against the
        // dashboard (Inertia/session) controller rather than the JSON API
        // one — the two controllers have independent `rules()` methods.
        $this->actingAs($user)
            ->patch(route('accounts.integrations.webhooks.update', [$team, $endpoint]), [
                'url' => 'https://example.test/dashboard-hook',
                'events' => [
                    WebhookEventType::CredentialExpired->value,
                    WebhookEventType::BuildCompleted->value,
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $endpoint->refresh();
        $this->assertSame('https://example.test/dashboard-hook', $endpoint->url);
        $this->assertContains(WebhookEventType::CredentialExpired->value, $endpoint->subscribed_events);
        $this->assertContains(WebhookEventType::BuildCompleted->value, $endpoint->subscribed_events);

        $this->actingAs($user)
            ->post(route('accounts.integrations.webhooks.store', $team), [
                'name' => 'New dashboard hook',
                'url' => 'https://example.test/new-dashboard',
                'events' => [WebhookEventType::CredentialExpired->value],
            ])
            ->assertSessionHasErrors('events.0');
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

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: Credential, 4: AppIdentifier} */
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

        return [$user->fresh(), $team, $app, $credential, $identifier];
    }

    /**
     * Simulates an endpoint that subscribed to a credential event back when
     * the instance ran `full`, before it switched to `dashboard_only`. It
     * cannot be created this way through either controller under the
     * current profile, which is exactly the point.
     */
    private function credentialSubscribedEndpoint(Team $team): WebhookEndpoint
    {
        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::Webhook,
            'provider' => IntegrationProvider::Http,
        ]);
        $endpoint = WebhookEndpoint::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'subscribed_events' => [
                WebhookEventType::CredentialExpired->value,
                WebhookEventType::BuildCompleted->value,
            ],
        ]);
        $integration->update(['external_id' => $endpoint->id]);

        return $endpoint;
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
