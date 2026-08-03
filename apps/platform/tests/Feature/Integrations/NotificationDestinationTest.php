<?php

namespace Tests\Feature\Integrations;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\TeamRole;
use App\Enums\WebhookEventType;
use App\Integrations\Notifications\NotificationEventCatalog;
use App\Integrations\Notifications\NotificationSender;
use App\Integrations\Notifications\NotificationTemplateRenderer;
use App\Jobs\Notifications\DeliverNotification;
use App\Jobs\Notifications\PublishNotificationEvent;
use App\Models\Integration;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationDestinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'integrations.enabled' => true,
            'integrations.notifications.enabled' => true,
        ]);
    }

    public function test_notifications_page_exposes_event_specific_templates_and_values(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);

        $this->actingAs($owner)
            ->get(route('accounts.integrations.notifications.index', $team))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Integrations/Index')
                ->where('activeTab', 'notifications')
                ->where('notificationsEnabled', true)
                ->has('notificationEvents', 18)
                ->where('notificationEvents.0.group', 'Application events')
                ->where('notificationEvents.2.group', 'App Identifiers and Platforms')
                ->where('notificationEvents.5.group', 'Credentials')
                ->where('notificationEvents.12.key', 'credential.verification_failed')
                ->where('notificationEvents.13.group', 'Builds')
                ->where('notificationEvents.17.group', 'Team Management')
                ->where('notificationEvents.8.key', 'credential.expiring_30_days')
                ->where('notificationEvents.8.variables.0.key', 'app.name')
                ->where('notificationEvents.8.variables.4.key', 'credential.days_remaining')
                ->where('notificationEvents.15.key', 'build.completed')
                ->where('notificationEvents.15.variables.0.key', 'app.name')
                ->where('notificationEvents.15.variables.2.key', 'build.status')
                ->where('notificationEvents.15.variables.2.example', 'completed')
                ->where('notificationEvents.15.variables.3.key', 'build.status_icon')
                ->has('notificationEvents.15.variables.3.options', 3)
                ->where('notificationEvents.15.variables.3.options.0.label', 'completed')
                ->where('notificationEvents.15.variables.3.options.0.value', '✅')
                ->where('notificationEvents.15.variables.3.options.1.label', 'failed')
                ->where('notificationEvents.15.variables.3.options.1.value', '❌')
                ->where('notificationEvents.15.variables.3.options.2.label', 'cancelled')
                ->where('notificationEvents.15.variables.3.options.2.value', '⚪')
                ->has('notificationProviders', 3)
                ->where('notificationProviders.0.value', 'slack')
                ->where('notificationProviders.1.value', 'discord')
                ->where('notificationProviders.2.value', 'telegram')
                ->where('selectedNotificationDestinationId', null)
                ->where('notificationDestinations', []));
    }

    public function test_selected_query_parameter_exposes_a_destination_from_the_current_team(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $this->destination($team, $owner);
        $selected = $this->destination($team, $owner, channel: '#selected');
        [$otherOwner, $otherTeam] = $this->member(TeamRole::Owner);
        $otherDestination = $this->destination($otherTeam, $otherOwner);

        $this->actingAs($owner)
            ->get(route('accounts.integrations.notifications.index', [
                'team' => $team,
                'selected' => $selected->id,
            ]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedNotificationDestinationId', $selected->id));

        $this->actingAs($owner)
            ->get(route('accounts.integrations.notifications.index', [
                'team' => $team,
                'selected' => $otherDestination->id,
            ]))
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedNotificationDestinationId', null));
    }

    public function test_owner_can_create_update_pause_and_remove_a_destination(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $templates = NotificationEventCatalog::defaultTemplates();

        $this->actingAs($owner)
            ->post(route('accounts.integrations.notifications.store', $team), [
                'name' => 'Release room',
                'provider' => 'slack',
                'destination' => '#mobile-releases',
                'webhook_url' => 'https://hooks.slack.com/services/T000/B000/secret',
                'events' => ['build.completed', 'artifact.created'],
                'templates' => $templates,
                'enabled' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $destination = NotificationDestination::with('integration')->sole();
        $this->assertSame(IntegrationKind::Notification, $destination->integration->kind);
        $this->assertSame(IntegrationProvider::Slack, $destination->integration->provider);
        $this->assertSame(IntegrationStatus::Active, $destination->integration->status);
        $this->assertSame(
            'https://hooks.slack.com/services/T000/B000/secret',
            $destination->credentials['webhook_url'],
        );
        $this->assertStringNotContainsString(
            'hooks.slack.com',
            (string) $destination->getRawOriginal('credentials'),
        );
        $this->assertSame(['build.completed', 'artifact.created'], $destination->subscribed_events);
        $this->assertSame($destination->id, $destination->integration->external_id);

        $templates['build.completed'] = '{{build.status_icon}} Build #{{build.number}} is {{build.status}}';

        $this->actingAs($owner)
            ->patch(route('accounts.integrations.notifications.update', [$team, $destination]), [
                'name' => 'Build room',
                'provider' => 'slack',
                'destination' => 'release-chat',
                'webhook_url' => '',
                'events' => [],
                'templates' => $templates,
                'enabled' => false,
            ])
            ->assertRedirect();

        $destination->refresh();
        $this->assertSame('release-chat', $destination->destination);
        $this->assertSame(
            'https://hooks.slack.com/services/T000/B000/secret',
            $destination->credentials['webhook_url'],
        );
        $this->assertSame([], $destination->subscribed_events);
        $this->assertSame($templates['build.completed'], $destination->templates['build.completed']);
        $this->assertSame('Build room', $destination->integration->name);
        $this->assertSame(IntegrationStatus::Disabled, $destination->integration->status);
        $this->assertNotNull($destination->integration->disabled_at);

        $this->actingAs($owner)
            ->delete(route('accounts.integrations.notifications.destroy', [$team, $destination]))
            ->assertRedirect();

        $this->assertSoftDeleted($destination);
        $this->assertSame(
            IntegrationStatus::Disconnected,
            Integration::withTrashed()->findOrFail($destination->integration_id)->status,
        );
    }

    public function test_templates_reject_values_that_do_not_belong_to_the_event(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $templates = NotificationEventCatalog::defaultTemplates();
        $templates['build.completed'] = 'Download {{artifact.download_url}}';

        $this->actingAs($owner)
            ->post(route('accounts.integrations.notifications.store', $team), [
                'name' => 'Build room',
                'provider' => 'slack',
                'destination' => '#builds',
                'webhook_url' => 'https://hooks.slack.com/services/T000/B000/secret',
                'events' => ['build.completed'],
                'templates' => $templates,
                'enabled' => true,
            ])
            ->assertSessionHasErrors('templates.build.completed');

        $this->assertDatabaseCount('notification_destinations', 0);
    }

    public function test_non_managers_and_other_teams_cannot_change_a_destination(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        [$viewer, $viewerTeam] = $this->member(TeamRole::Viewer);
        $destination = $this->destination($team, $owner);
        $payload = [
            'name' => 'Changed',
            'provider' => 'slack',
            'destination' => '#changed',
            'webhook_url' => '',
            'events' => [],
            'templates' => NotificationEventCatalog::defaultTemplates(),
            'enabled' => true,
        ];

        $this->actingAs($viewer)
            ->patch(route('accounts.integrations.notifications.update', [$viewerTeam, $destination]), $payload)
            ->assertNotFound();

        $viewer->teams()->syncWithoutDetaching([$team->id => ['role' => TeamRole::Viewer->value]]);

        $this->actingAs($viewer)
            ->patch(route('accounts.integrations.notifications.update', [$team, $destination]), $payload)
            ->assertForbidden();
    }

    public function test_each_provider_can_send_a_real_contract_test_without_exposing_credentials(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        Http::fake([
            'https://hooks.slack.com/*' => Http::response('ok'),
            'https://discord.com/*' => Http::response(['id' => 'message-1']),
            'https://api.telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        config(['integrations.notifications.telegram_api_url' => 'https://api.telegram.test']);

        $slack = $this->destination(
            $team,
            $owner,
            IntegrationProvider::Slack,
            ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/secret'],
            '#releases',
        );
        $discord = $this->destination(
            $team,
            $owner,
            IntegrationProvider::Discord,
            ['webhook_url' => 'https://discord.com/api/webhooks/123/secret'],
            '#builds',
        );
        $telegram = $this->destination(
            $team,
            $owner,
            IntegrationProvider::Telegram,
            ['bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz'],
            '-1001234567890',
        );

        foreach ([$slack, $discord, $telegram] as $destination) {
            $this->actingAs($owner)
                ->post(route('accounts.integrations.notifications.test', [$team, $destination]), [
                    'event' => 'build.completed',
                    'template' => '{{build.status_icon}} {{app.name}} build #{{build.number}} {{build.status}} in {{build.duration}} {{build.url}}',
                ])
                ->assertRedirect()
                ->assertSessionHas('status');

            $this->assertNotNull($destination->fresh()->last_delivered_at);
        }

        Http::assertSent(fn (HttpRequest $request) => $request->url()
            === 'https://hooks.slack.com/services/T000/B000/secret'
            && $request['text'] === '✅ Atlas Mobile build #482 completed in 8m 42s https://manager.example/builds/482');
        Http::assertSent(fn (HttpRequest $request) => str_starts_with(
            $request->url(),
            'https://discord.com/api/webhooks/123/secret?',
        ) && $request['content'] === '✅ Atlas Mobile build #482 completed in 8m 42s https://manager.example/builds/482'
            && $request['allowed_mentions'] === ['parse' => []]);
        Http::assertSent(fn (HttpRequest $request) => $request->url()
            === 'https://api.telegram.test/bot123456789:abcdefghijklmnopqrstuvwxyz/sendMessage'
            && $request['chat_id'] === '-1001234567890'
            && $request['text'] === '✅ Atlas Mobile build #482 completed in 8m 42s https://manager.example/builds/482');
    }

    public function test_event_example_rejects_values_from_another_event(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);
        $destination = $this->destination($team, $owner);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.notifications.test', [$team, $destination]), [
                'event' => 'build.completed',
                'template' => 'Download {{artifact.download_url}}',
            ])
            ->assertSessionHasErrors('test');
    }

    public function test_provider_credentials_are_required_and_restricted_to_official_endpoints(): void
    {
        [$owner, $team] = $this->member(TeamRole::Owner);

        $this->actingAs($owner)
            ->post(route('accounts.integrations.notifications.store', $team), [
                'name' => 'Unsafe room',
                'provider' => 'slack',
                'destination' => '#builds',
                'webhook_url' => 'https://example.test/services/secret',
                'events' => ['build.completed'],
                'templates' => NotificationEventCatalog::defaultTemplates(),
                'enabled' => true,
            ])
            ->assertSessionHasErrors('webhook_url');

        $this->assertDatabaseCount('notification_destinations', 0);
    }

    public function test_subscribed_events_are_rendered_and_delivered_once_through_the_outbox(): void
    {
        Queue::fake();
        [$owner, $team] = $this->member(TeamRole::Owner);
        $destination = $this->destination($team, $owner);
        $event = WebhookEvent::create([
            'team_id' => $team->id,
            'event_type' => WebhookEventType::BuildCompleted,
            'schema_version' => 2,
            'aggregate_type' => 'BuildRecord',
            'aggregate_id' => '019fbcba-10d2-7df2-9c8e-af6504ef02bd',
            'source' => 'web',
            'payload' => [
                'id' => '019fbcba-10d2-7df2-9c8e-af6504ef02bd',
                'profile_type' => 'production',
                'status' => 'completed',
                'started_at' => now()->subMinutes(2)->toISOString(),
                'finished_at' => now()->toISOString(),
                'actor' => ['type' => 'user', 'display_name' => $owner->name],
            ],
            'filter_context' => ['mobile_app_ids' => []],
            'occurred_at' => now(),
            'published_at' => now(),
        ]);

        $publisher = new PublishNotificationEvent($event->id);
        $publisher->handle(app(NotificationTemplateRenderer::class));
        $publisher->handle(app(NotificationTemplateRenderer::class));

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDeliveryStatus::Pending, $delivery->status);
        $this->assertStringContainsString('019fbcba', $delivery->rendered_message);
        $this->assertNotNull($event->fresh()->notifications_published_at);
        Queue::assertPushed(DeliverNotification::class, fn ($job) => $job->deliveryId === $delivery->id);

        Http::fake(['https://hooks.slack.com/*' => Http::response('ok')]);
        (new DeliverNotification($delivery->id))->handle(app(NotificationSender::class));

        $this->assertSame(NotificationDeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->assertNotNull($destination->fresh()->last_delivered_at);
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    /** @param array<string, string> $credentials */
    private function destination(
        Team $team,
        User $owner,
        IntegrationProvider $provider = IntegrationProvider::Slack,
        array $credentials = ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/secret'],
        string $channel = '#releases',
    ): NotificationDestination {
        $integration = Integration::create([
            'team_id' => $team->id,
            'kind' => IntegrationKind::Notification,
            'provider' => $provider,
            'name' => $provider->label().' release room',
            'status' => IntegrationStatus::Active,
            'created_by_user_id' => $owner->id,
            'updated_by_user_id' => $owner->id,
        ]);

        return NotificationDestination::create([
            'team_id' => $team->id,
            'integration_id' => $integration->id,
            'destination' => $channel,
            'credentials' => $credentials,
            'subscribed_events' => ['build.completed'],
            'templates' => NotificationEventCatalog::defaultTemplates(),
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
