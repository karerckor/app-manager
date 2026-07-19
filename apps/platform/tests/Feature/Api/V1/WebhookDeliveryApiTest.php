<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ApiKeyScope;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\TeamRole;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\Webhooks\DeliverWebhook;
use App\Models\Integration;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookDeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_deliveries_with_cursor_and_hides_payload(): void
    {
        [$user, $team, $endpoint, $delivery] = $this->ctx();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::DeliveriesRead->value])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/v1/{$team->slug}/webhooks/{$endpoint->id}/deliveries?per_page=1")
            ->assertOk()
            ->assertJsonPath('data.0.id', $delivery->id)
            ->assertJsonStructure(['data', 'meta' => ['per_page', 'next_cursor']]);

        $response->assertJsonMissingPath('data.0.payload_snapshot');
    }

    public function test_redeliver_creates_linked_delivery(): void
    {
        [$user, $team, $endpoint, $delivery] = $this->ctx();
        Queue::fake();
        $token = $user->createTeamApiKey($team, 'k', [ApiKeyScope::WebhooksWrite->value])->plainTextToken;

        $new = $this->withToken($token)
            ->postJson("/api/v1/{$team->slug}/webhooks/{$endpoint->id}/deliveries/{$delivery->id}/redeliver")
            ->assertCreated()
            ->assertJsonPath('data.redelivery_of_id', $delivery->id)
            ->json('data.id');

        $this->assertNotSame($delivery->id, $new);
        Queue::assertPushed(DeliverWebhook::class);
    }

    /** @return array{User, Team, WebhookEndpoint, WebhookDelivery} */
    private function ctx(): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->addUser($user, TeamRole::Owner);
        $integration = Integration::create([
            'team_id' => $team->id, 'kind' => IntegrationKind::Webhook,
            'provider' => IntegrationProvider::Http, 'name' => 'hook',
            'status' => IntegrationStatus::Active,
        ]);
        $endpoint = WebhookEndpoint::factory()->create([
            'team_id' => $team->id, 'integration_id' => $integration->id,
        ]);
        $event = WebhookEvent::create([
            'team_id' => $team->id,
            'event_type' => \App\Enums\WebhookEventType::MobileAppCreated,
            'schema_version' => 1,
            'aggregate_type' => 'MobileApp',
            'aggregate_id' => null,
            'payload' => ['object' => ['id' => 'x']],
            'occurred_at' => now(),
        ]);
        $delivery = WebhookDelivery::factory()->create([
            'team_id' => $team->id,
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
            'status' => WebhookDeliveryStatus::Exhausted,
            'payload_snapshot' => ['id' => $event->id, 'type' => 'mobile_app.created'],
        ]);

        return [$user, $team, $endpoint, $delivery];
    }
}
