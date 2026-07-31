<?php

namespace Tests\Feature\Integrations;

use App\Enums\ApiKeyScope;
use App\Enums\BuildStatus;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\ProfileType;
use App\Enums\TeamRole;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Jobs\Webhooks\DeliverWebhook;
use App\Jobs\Webhooks\PublishWebhookEvent;
use App\Models\AppIdentifier;
use App\Models\BuildRecord;
use App\Models\Integration;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookInspectorAndFiltersTest extends TestCase
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

    public function test_endpoint_filters_are_validated_normalized_and_exposed_on_the_web_page(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'tags' => [['Release', '#6366f1']],
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.webhooks.store', $team), [
                'name' => 'Production failures',
                'url' => 'https://hooks.example.test/releases',
                'events' => [WebhookEventType::BuildCompleted->value],
                'filters' => [
                    'mobile_app_ids' => [$app->id],
                    'tags' => [' Release ', 'release'],
                    'build_statuses' => [BuildStatus::Failed->value],
                    'profile_types' => [ProfileType::Production->value],
                ],
            ])
            ->assertRedirect();

        $endpoint = WebhookEndpoint::query()->sole();

        $this->assertSame([
            'mobile_app_ids' => [$app->id],
            'tags' => ['Release'],
            'build_statuses' => ['failed'],
            'profile_types' => ['production'],
        ], $endpoint->filters);

        $this->actingAs($owner)
            ->get(route('accounts.integrations.webhooks.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->where('webhookEndpoints.0.filters.mobile_app_ids.0', $app->id)
                ->where('webhookEndpoints.0.filters.tags.0', 'Release')
                ->where('buildStatuses', array_map(
                    fn (BuildStatus $status) => $status->value,
                    BuildStatus::cases(),
                )));
    }

    public function test_endpoint_rejects_an_application_from_another_team(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $otherTeam = Team::factory()->create();
        $otherApp = MobileApp::factory()->create(['team_id' => $otherTeam->id]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.webhooks.store', $team), [
                'name' => 'Invalid scope',
                'url' => 'https://hooks.example.test/releases',
                'events' => [WebhookEventType::BuildCompleted->value],
                'filters' => ['mobile_app_ids' => [$otherApp->id]],
            ])
            ->assertSessionHasErrors('filters.mobile_app_ids.0');

        $this->assertDatabaseCount('webhook_endpoints', 0);
    }

    public function test_api_accepts_and_returns_endpoint_filters(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $token = $owner->createTeamApiKey(
            $team,
            'Webhook manager',
            [ApiKeyScope::WebhooksWrite->value],
        )->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/{$team->slug}/webhooks", [
                'name' => 'Filtered endpoint',
                'url' => 'https://hooks.example.test/releases',
                'events' => [WebhookEventType::BuildCompleted->value],
                'filters' => [
                    'mobile_app_ids' => [$app->id],
                    'tags' => ['release'],
                    'build_statuses' => ['failed'],
                    'profile_types' => ['production'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.filters.mobile_app_ids.0', $app->id)
            ->assertJsonPath('data.filters.tags.0', 'release')
            ->assertJsonPath('data.filters.build_statuses.0', 'failed')
            ->assertJsonPath('data.filters.profile_types.0', 'production');
    }

    public function test_publisher_uses_the_immutable_event_context_for_endpoint_filters(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member(TeamRole::Owner);
        $app = MobileApp::factory()->create([
            'team_id' => $team->id,
            'tags' => [['Release', '#6366f1']],
        ]);
        $identifier = AppIdentifier::factory()->create(['mobile_app_id' => $app->id]);
        $build = BuildRecord::factory()->create([
            'team_id' => $team->id,
            'mobile_app_id' => $app->id,
            'app_identifier_id' => $identifier->id,
            'requested_by_user_id' => $owner->id,
            'status' => BuildStatus::Failed,
            'profile_type' => ProfileType::Production,
        ]);
        $matching = $this->endpoint($team, $owner, [
            'mobile_app_ids' => [$app->id],
            'tags' => ['release'],
            'build_statuses' => ['failed'],
            'profile_types' => ['production'],
        ]);
        $notMatching = $this->endpoint($team, $owner, [
            'build_statuses' => ['completed'],
        ]);

        $event = app(WebhookEventRecorder::class)->record(
            $team,
            WebhookEventType::BuildCompleted,
            $build,
            WebhookPayload::build($build),
        );

        $app->syncTags([['Changed later', null]])->save();
        (new PublishWebhookEvent($event->id))->handle();

        $this->assertSame([$app->id], $event->filter_context['mobile_app_ids']);
        $this->assertSame(['release'], $event->filter_context['tags']);
        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $matching->id,
        ]);
        $this->assertDatabaseMissing('webhook_deliveries', [
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $notMatching->id,
        ]);
    }

    public function test_explicit_test_delivery_bypasses_endpoint_filters(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member(TeamRole::Owner);
        $endpoint = $this->endpoint($team, $owner, [
            'build_statuses' => ['failed'],
            'profile_types' => ['production'],
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.webhooks.test', [$team, $endpoint]))
            ->assertRedirect();

        $event = WebhookEvent::query()->sole();
        (new PublishWebhookEvent($event->id))->handle();

        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
        ]);
    }

    public function test_web_inspector_shows_delivery_details_and_queues_a_redelivery(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member(TeamRole::Owner);
        $endpoint = $this->endpoint($team, $owner);
        $event = WebhookEvent::create([
            'team_id' => $team->id,
            'event_type' => WebhookEventType::BuildCompleted,
            'schema_version' => 2,
            'aggregate_type' => 'BuildRecord',
            'aggregate_id' => null,
            'payload' => ['status' => 'failed'],
            'filter_context' => [],
            'occurred_at' => now(),
        ]);
        $delivery = WebhookDelivery::factory()->create([
            'team_id' => $team->id,
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
            'status' => WebhookDeliveryStatus::Exhausted,
            'attempt_count' => 7,
            'payload_snapshot' => [
                'id' => $event->id,
                'type' => WebhookEventType::BuildCompleted->value,
                'data' => ['status' => 'failed'],
            ],
            'response_status' => 503,
            'duration_ms' => 912,
            'response_body' => 'temporarily unavailable',
            'last_error' => 'The endpoint returned HTTP 503.',
            'exhausted_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->getJson(route('accounts.integrations.webhooks.deliveries.show', [
                $team,
                $endpoint,
                $delivery,
            ]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id', $delivery->id)
            ->assertJsonPath('data.payload.data.status', 'failed')
            ->assertJsonPath('data.response_status', 503)
            ->assertJsonPath('data.duration_ms', 912)
            ->assertJsonPath('data.last_error', 'The endpoint returned HTTP 503.');

        $this->assertStringNotContainsString($endpoint->secret, $response->getContent());

        $this->actingAs($owner)
            ->post(route('accounts.integrations.webhooks.deliveries.redeliver', [
                $team,
                $endpoint,
                $delivery,
            ]))
            ->assertRedirect()
            ->assertSessionHas('webhook_redelivery_id');

        $redelivery = WebhookDelivery::query()
            ->where('redelivery_of_id', $delivery->id)
            ->sole();
        $this->assertSame($delivery->payload_snapshot, $redelivery->payload_snapshot);
        Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->deliveryId === $redelivery->id);
    }

    public function test_web_redelivery_rejects_a_non_terminal_delivery(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member(TeamRole::Owner);
        $endpoint = $this->endpoint($team, $owner);
        $event = WebhookEvent::create([
            'team_id' => $team->id,
            'event_type' => WebhookEventType::BuildCompleted,
            'schema_version' => 2,
            'aggregate_type' => 'BuildRecord',
            'payload' => [],
            'occurred_at' => now(),
        ]);
        $delivery = WebhookDelivery::factory()->create([
            'team_id' => $team->id,
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
            'status' => WebhookDeliveryStatus::RetryScheduled,
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.webhooks.deliveries.redeliver', [
                $team,
                $endpoint,
                $delivery,
            ]))
            ->assertConflict();

        $this->assertDatabaseCount('webhook_deliveries', 1);
        Queue::assertNotPushed(DeliverWebhook::class);
    }

    public function test_non_admin_cannot_inspect_a_delivery(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $viewer = User::factory()->create();
        $team->addUser($viewer, TeamRole::Viewer);
        $endpoint = $this->endpoint($team, $owner);
        $event = WebhookEvent::create([
            'team_id' => $team->id,
            'event_type' => WebhookEventType::MobileAppCreated,
            'schema_version' => 2,
            'aggregate_type' => 'MobileApp',
            'payload' => [],
            'occurred_at' => now(),
        ]);
        $delivery = WebhookDelivery::factory()->create([
            'team_id' => $team->id,
            'webhook_event_id' => $event->id,
            'webhook_endpoint_id' => $endpoint->id,
        ]);

        $this->actingAs($viewer)
            ->getJson(route('accounts.integrations.webhooks.deliveries.show', [
                $team,
                $endpoint,
                $delivery,
            ]))
            ->assertForbidden();
    }

    /**
     * @param  array<string, list<string>>  $filters
     */
    private function endpoint(
        Team $team,
        User $user,
        array $filters = [],
    ): WebhookEndpoint {
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::Webhook,
            'provider' => IntegrationProvider::Http,
            'name' => 'Test endpoint',
            'status' => IntegrationStatus::Active,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);

        return WebhookEndpoint::factory()->create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'subscribed_events' => [WebhookEventType::BuildCompleted->value],
            'filters' => $filters,
        ]);
    }

    /** @return array{User, Team} */
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
