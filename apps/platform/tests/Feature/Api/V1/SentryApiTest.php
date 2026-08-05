<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\Platform;
use App\Enums\SentryEnvelopeStatus;
use App\Enums\TeamRole;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\SentryDsn;
use App\Models\SentryEnvelope;
use App\Models\SentryEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SentryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integrations.enabled' => true, 'integrations.sentry.enabled' => true]);
    }

    public function test_sentry_dsn_can_be_managed_through_the_api(): void
    {
        [$user, $team, $app, $identifier] = $this->context();
        $token = $user->createTeamApiKey($team, 'sentry-admin', [ApiKeyScope::IntegrationsRead->value, ApiKeyScope::IntegrationsWrite->value])->plainTextToken;
        $base = "/api/v1/{$team->slug}/integrations/sentry";
        $payload = ['name' => 'Customer relay', 'routing_method' => 'query', 'event_source' => null, 'app_key' => 'app_slug', 'identifier_key' => 'app_identifier', 'routes' => [['mobile_app_id' => $app->id, 'app_identifier_id' => $identifier->id]], 'forward_enabled' => false, 'upstream_dsn' => ''];

        $created = $this->withToken($token)->postJson($base, $payload)->assertCreated()->assertJsonPath('data.name', 'Customer relay')->assertJsonPath('data.routes.0.app_slug', $app->slug);
        $dsnId = $created->json('data.id');
        $this->withToken($token)->getJson($base)->assertOk()->assertJsonPath('data.0.id', $dsnId);
        $this->withToken($token)->patchJson("{$base}/{$dsnId}", [...$payload, 'name' => 'Renamed relay', 'enabled' => false])->assertOk()->assertJsonPath('data.name', 'Renamed relay')->assertJsonPath('data.enabled', false);
        $this->withToken($token)->deleteJson("{$base}/{$dsnId}")->assertNoContent();
    }

    public function test_app_issues_and_traces_can_be_inspected_through_the_api(): void
    {
        [$user, $team, $app, $identifier] = $this->context();
        $token = $user->createTeamApiKey($team, 'sentry-reader', [ApiKeyScope::IntegrationsWrite->value, ApiKeyScope::AppsRead->value])->plainTextToken;
        $payload = ['name' => 'Customer relay', 'routing_method' => 'query', 'event_source' => null, 'app_key' => 'app_slug', 'identifier_key' => 'app_identifier', 'routes' => [['mobile_app_id' => $app->id, 'app_identifier_id' => $identifier->id]], 'forward_enabled' => false, 'upstream_dsn' => ''];
        $dsnId = $this->withToken($token)->postJson("/api/v1/{$team->slug}/integrations/sentry", $payload)->assertCreated()->json('data.id');
        $dsn = SentryDsn::findOrFail($dsnId);
        $traceId = str_repeat('a', 32);

        $error = $this->event($team, $app, $identifier, $dsn, 'event', 'Payment failed', $traceId, ['user' => ['id' => 'buyer-42'], 'request' => ['method' => 'POST'], 'exception' => ['values' => [['type' => 'PaymentError', 'value' => 'Payment failed']]]]);
        $trace = $this->event($team, $app, $identifier, $dsn, 'transaction', 'POST /checkout', $traceId, ['start_timestamp' => 100.0, 'timestamp' => 100.25, 'contexts' => ['trace' => ['trace_id' => $traceId, 'op' => 'http.server']]]);
        $base = "/api/v1/{$team->slug}/apps/{$app->slug}/telemetry";

        $issueId = $this->withToken($token)->getJson("{$base}/issues?environment=production")->assertOk()->assertJsonPath('data.0.events_count', 1)->json('data.0.id');
        $this->withToken($token)->getJson("{$base}/issues/{$issueId}?event={$error->id}")->assertOk()->assertJsonPath('data.selected_event.user.id', 'buyer-42')->assertJsonPath('data.selected_event.request.method', 'POST');
        $this->withToken($token)->getJson("{$base}/traces")->assertOk()->assertJsonPath('data.0.trace_id', $traceId);
        $this->withToken($token)->getJson("{$base}/traces/{$traceId}?event={$trace->id}")->assertOk()->assertJsonPath('data.events_count', 2)->assertJsonPath('data.selected_event.trace.duration_ms', 250);
    }

    /** @return array{User, Team, MobileApp, AppIdentifier} */
    private function context(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id, 'slug' => 'customer-app']);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id, 'platform' => Platform::Web, 'identifier' => 'customer.example.test']);

        return [$user, $team, $app, $identifier];
    }

    /** @param array<string, mixed> $payload */
    private function event(Team $team, MobileApp $app, AppIdentifier $identifier, SentryDsn $dsn, string $type, string $title, string $traceId, array $payload): SentryEvent
    {
        $envelope = SentryEnvelope::create(['team_id' => $team->id, 'sentry_dsn_id' => $dsn->id, 'mobile_app_id' => $app->id, 'app_identifier_id' => $identifier->id, 'header' => [], 'body_base64' => base64_encode('{}'), 'status' => SentryEnvelopeStatus::Stored]);

        return SentryEvent::create(['team_id' => $team->id, 'sentry_dsn_id' => $dsn->id, 'sentry_envelope_id' => $envelope->id, 'mobile_app_id' => $app->id, 'app_identifier_id' => $identifier->id, 'event_id' => bin2hex(random_bytes(16)), 'item_type' => $type, 'level' => $type === 'event' ? 'error' : 'info', 'title' => $title, 'transaction' => $type === 'transaction' ? $title : null, 'environment' => 'production', 'platform' => 'javascript', 'trace_id' => $traceId, 'occurred_at' => now(), 'payload' => $payload]);
    }
}
