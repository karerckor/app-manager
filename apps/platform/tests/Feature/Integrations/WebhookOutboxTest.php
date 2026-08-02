<?php

namespace Tests\Feature\Integrations;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Integrations\Webhooks\WebhookUrlGuard;
use App\Jobs\Webhooks\DeliverWebhook;
use App\Jobs\Webhooks\PublishWebhookEvent;
use App\Models\Credential;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Support\Audit\ActorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'integrations.enabled' => true,
            'integrations.webhooks.enabled' => true,
            'webhooks.allow_private_addresses' => true,
        ]);
    }

    public function test_owner_can_create_update_rotate_and_delete_a_webhook_endpoint(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.webhooks.store', $team), [
                'name' => 'Release events',
                'url' => 'https://hooks.example.test/releases',
                'events' => [WebhookEventType::BuildCompleted->value],
            ])
            ->assertRedirect()
            ->assertSessionHas('webhook_secret');

        $endpoint = WebhookEndpoint::query()->with('integration')->sole();
        $secret = session('webhook_secret.secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame($secret, $endpoint->secret);
        $this->assertStringNotContainsString(
            $secret,
            (string) DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret'),
        );

        $this->actingAs($owner)
            ->patch(route('accounts.integrations.webhooks.update', [$team, $endpoint]), [
                'name' => 'Deploy events',
                'url' => 'https://hooks.example.test/deploys',
                'events' => [WebhookEventType::BuildStarted->value, WebhookEventType::BuildCompleted->value],
                'enabled' => false,
            ])
            ->assertRedirect();

        $this->assertSame('Deploy events', $endpoint->integration->refresh()->name);
        $this->assertSame(IntegrationStatus::Disabled, $endpoint->integration->status);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.webhooks.rotate-secret', [$team, $endpoint]))
            ->assertRedirect()
            ->assertSessionHas('webhook_secret');
        $this->assertNotSame($secret, $endpoint->refresh()->secret);

        $this->actingAs($owner)
            ->delete(route('accounts.integrations.webhooks.destroy', [$team, $endpoint]))
            ->assertRedirect();

        $this->assertSoftDeleted('webhook_endpoints', ['id' => $endpoint->id]);
        $this->assertSame(IntegrationStatus::Disconnected, $endpoint->integration->refresh()->status);
    }

    public function test_contributor_cannot_manage_endpoints_and_private_urls_are_rejected(): void
    {
        [$contributor, $team] = $this->member(TeamRole::Contributor);

        $this->actingAs($contributor)
            ->post(route('accounts.integrations.webhooks.store', $team), [
                'name' => 'Forbidden',
                'url' => 'https://hooks.example.test',
                'events' => [WebhookEventType::BuildCompleted->value],
            ])
            ->assertForbidden();

        [$owner, $ownerTeam] = $this->member(TeamRole::Owner);
        config(['webhooks.allow_private_addresses' => false]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.webhooks.store', $ownerTeam), [
                'name' => 'Internal',
                'url' => 'http://127.0.0.1:8080/hook',
                'events' => [WebhookEventType::BuildCompleted->value],
            ])
            ->assertSessionHasErrors('url');
    }

    public function test_outbox_publishes_a_signed_safe_payload_and_marks_delivery_successful(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member(TeamRole::Owner);
        $endpoint = $this->endpoint($team, $owner, [WebhookEventType::MobileAppCreated->value]);
        $mobileApp = MobileApp::factory()->create(['team_id' => $team->id]);
        $event = app(WebhookEventRecorder::class)->record(
            $team,
            WebhookEventType::MobileAppCreated,
            $mobileApp,
            [
                'id' => $mobileApp->id,
                'name' => $mobileApp->name,
            ],
            ActorContext::forUser($owner, 'web'),
        );

        Queue::assertPushed(PublishWebhookEvent::class, fn ($job) => $job->eventId === $event->id);
        (new PublishWebhookEvent($event->id))->handle();

        $delivery = WebhookDelivery::query()->with(['event', 'endpoint.integration'])->sole();
        $this->assertSame(WebhookDeliveryStatus::Pending, $delivery->status);
        $this->assertArrayNotHasKey('payload', $delivery->payload_snapshot['data']);
        Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->deliveryId === $delivery->id);

        Http::fake(['https://hooks.example.test/*' => Http::response('ok', 204)]);
        (new DeliverWebhook($delivery->id))->handle(app(WebhookUrlGuard::class));

        $this->assertSame(WebhookDeliveryStatus::Delivered, $delivery->refresh()->status);
        $this->assertSame(204, $delivery->response_status);
        $this->assertSame(0, $endpoint->refresh()->consecutive_failures);
        Http::assertSent(function (ClientRequest $request) use ($delivery, $endpoint) {
            $timestamp = $request->header('X-App-Manager-Timestamp')[0];
            $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $endpoint->secret);

            return $request->header('X-App-Manager-Event')[0] === WebhookEventType::MobileAppCreated->value
                && $request->header('X-App-Manager-Delivery')[0] === $delivery->id
                && $request->header('X-App-Manager-Signature')[0] === $expected;
        });
    }

    public function test_failed_delivery_is_scheduled_for_retry(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member(TeamRole::Owner);
        $endpoint = $this->endpoint($team, $owner, [WebhookEventType::WebhookTest->value]);
        $event = WebhookEvent::create([
            'team_id' => $team->id,
            'event_type' => WebhookEventType::WebhookTest,
            'schema_version' => 1,
            'aggregate_type' => 'WebhookEndpoint',
            'aggregate_id' => $endpoint->id,
            'actor_user_id' => $owner->id,
            'payload' => ['endpoint_id' => $endpoint->id],
            'occurred_at' => now(),
        ]);
        (new PublishWebhookEvent($event->id))->handle();
        $delivery = WebhookDelivery::query()->sole();
        Http::fake(['https://hooks.example.test/*' => Http::response('temporary', 503)]);

        (new DeliverWebhook($delivery->id))->handle(app(WebhookUrlGuard::class));

        $delivery->refresh();
        $this->assertSame(WebhookDeliveryStatus::RetryScheduled, $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertNotNull($delivery->next_attempt_at);
        Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_application_mutation_records_an_outbox_event_in_the_business_transaction(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member(TeamRole::Owner);

        $this->actingAs($owner)
            ->post(route('accounts.apps.store', $team), [
                'name' => 'Transactional app',
                'description' => 'Created through the application action.',
            ])
            ->assertRedirect();

        $mobileApp = MobileApp::query()->where('team_id', $team->id)->sole();
        $event = WebhookEvent::query()->sole();
        $this->assertSame(WebhookEventType::MobileAppCreated, $event->event_type);
        $this->assertSame($mobileApp->id, $event->aggregate_id);
        $this->assertSame($mobileApp->name, $event->payload['name']);
    }

    public function test_credential_projection_never_contains_encrypted_payload_values(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'payload' => ['private_key' => 'super-secret-key', 'password' => 'super-secret-password'],
        ]);

        $payload = WebhookPayload::credential($credential);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('super-secret-key', $encoded);
        $this->assertStringNotContainsString('super-secret-password', $encoded);
        $this->assertArrayNotHasKey('payload', $payload);
    }

    public function test_explicit_test_is_delivered_only_to_the_selected_endpoint_without_a_subscription(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member(TeamRole::Owner);
        $target = $this->endpoint($team, $owner, [WebhookEventType::BuildCompleted->value]);
        $other = $this->endpoint($team, $owner, [WebhookEventType::BuildCompleted->value]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.webhooks.test', [$team, $target]))
            ->assertRedirect();

        $event = WebhookEvent::query()->sole();
        $this->assertSame(WebhookEventType::WebhookTest, $event->event_type);
        (new PublishWebhookEvent($event->id))->handle();

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame($target->id, $delivery->webhook_endpoint_id);
        $this->assertNotSame($other->id, $delivery->webhook_endpoint_id);
    }

    public function test_disabled_feature_rejects_endpoint_management_and_does_not_grow_the_outbox(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member(TeamRole::Owner);
        config([
            'integrations.webhooks.enabled' => false,
            'integrations.notifications.enabled' => false,
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.webhooks.store', $team), [
                'name' => 'Disabled',
                'url' => 'https://hooks.example.test/events',
                'events' => [WebhookEventType::MobileAppCreated->value],
            ])
            ->assertNotFound();

        $this->actingAs($owner)
            ->post(route('accounts.apps.store', $team), ['name' => 'No outbox event'])
            ->assertRedirect();

        $this->assertDatabaseCount('webhook_endpoints', 0);
        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNotPushed(PublishWebhookEvent::class);
    }

    private function endpoint(Team $team, User $user, array $events): WebhookEndpoint
    {
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::Webhook,
            'provider' => IntegrationProvider::Http,
            'name' => 'Test endpoint',
            'status' => IntegrationStatus::Active,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);

        return WebhookEndpoint::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'url' => 'https://hooks.example.test/events',
            'secret' => 'whsec_test-secret',
            'secret_hint' => 'cret',
            'subscribed_events' => $events,
        ]);
    }

    private function member(TeamRole $role): array
    {
        $user = User::factory()->create();
        $owner = $role === TeamRole::Owner ? $user : User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($user, $role);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team];
    }
}
