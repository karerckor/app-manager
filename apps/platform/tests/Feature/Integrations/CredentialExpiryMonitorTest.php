<?php

namespace Tests\Feature\Integrations;

use App\Enums\WebhookEventType;
use App\Integrations\Notifications\NotificationEventCatalog;
use App\Integrations\Notifications\NotificationTemplateRenderer;
use App\Integrations\Webhooks\CredentialExpiryMonitor;
use App\Models\Credential;
use App\Models\NotificationDestination;
use App\Models\Team;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CredentialExpiryMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'integrations.enabled' => true,
            'integrations.webhooks.enabled' => false,
            'integrations.notifications.enabled' => true,
        ]);
    }

    public function test_it_records_each_current_expiry_milestone_once(): void
    {
        $at = CarbonImmutable::parse('2026-08-01 12:00:00');
        $team = Team::factory()->create();
        $outsideWindow = Credential::factory()->for($team)->create(['expires_at' => $at->addDays(31)]);
        $thirtyDayWarning = Credential::factory()->for($team)->create([
            'name' => 'Distribution certificate',
            'expires_at' => $at->addDays(20),
        ]);
        $sevenDayWarning = Credential::factory()->for($team)->create(['expires_at' => $at->addDays(5)]);
        $oneDayWarning = Credential::factory()->for($team)->create(['expires_at' => $at->addHours(12)]);
        $expired = Credential::factory()->for($team)->create([
            'name' => 'Expired certificate',
            'expires_at' => $at->subHour(),
        ]);

        $monitor = app(CredentialExpiryMonitor::class);

        $this->assertSame(4, $monitor->recordDue($at));
        $this->assertSame(0, $monitor->recordDue($at));
        $this->assertDatabaseMissing('webhook_events', ['aggregate_id' => $outsideWindow->id]);
        $this->assertExpiryEvent($thirtyDayWarning, WebhookEventType::CredentialExpiringIn30Days, 20);
        $this->assertExpiryEvent($sevenDayWarning, WebhookEventType::CredentialExpiringIn7Days, 5);
        $this->assertExpiryEvent($oneDayWarning, WebhookEventType::CredentialExpiringIn1Day, 1);
        $this->assertExpiryEvent($expired, WebhookEventType::CredentialExpired, 0);
        $this->assertDatabaseCount('webhook_events', 4);

        $expiredEvent = WebhookEvent::query()
            ->where('aggregate_id', $expired->id)
            ->sole();
        $destination = new NotificationDestination([
            'templates' => NotificationEventCatalog::defaultTemplates(),
        ]);
        $message = app(NotificationTemplateRenderer::class)->render($expiredEvent, $destination);

        $this->assertStringContainsString('Expired certificate expired on Aug 1, 2026', $message);
        $this->assertStringContainsString(route('accounts.credentials.index', $team), $message);
    }

    public function test_a_renewed_credential_can_enter_the_same_milestone_again(): void
    {
        $at = CarbonImmutable::parse('2026-08-01 12:00:00');
        $team = Team::factory()->create();
        $credential = Credential::factory()->for($team)->create([
            'expires_at' => $at->addDays(20),
        ]);
        $monitor = app(CredentialExpiryMonitor::class);

        $this->assertSame(1, $monitor->recordDue($at));

        $credential->update(['expires_at' => $at->addDays(60)]);

        $this->assertSame(1, $monitor->recordDue($at->addDays(31)));
        $this->assertSame(2, WebhookEvent::query()
            ->where('aggregate_id', $credential->id)
            ->where('event_type', WebhookEventType::CredentialExpiringIn30Days->value)
            ->count());
    }

    private function assertExpiryEvent(
        Credential $credential,
        WebhookEventType $eventType,
        int $daysRemaining,
    ): void {
        $event = WebhookEvent::query()
            ->where('aggregate_id', $credential->id)
            ->where('event_type', $eventType->value)
            ->sole();

        $this->assertSame($daysRemaining, $event->payload['days_remaining']);
        $this->assertSame($credential->expires_at->toISOString(), $event->payload['expires_at']);
        $this->assertSame('system', data_get($event->payload, 'actor.type'));
    }
}
