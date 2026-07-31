<?php

namespace App\Jobs\Webhooks;

use App\Enums\IntegrationStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Webhooks\WebhookSubscriptionMatcher;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class PublishWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $eventId) {}

    public function handle(): void
    {
        if (! config('integrations.enabled') || ! config('integrations.webhooks.enabled')) {
            return;
        }

        $deliveryIds = DB::transaction(function () {
            $event = WebhookEvent::query()->lockForUpdate()->findOrFail($this->eventId);

            if ($event->published_at !== null) {
                return $event->deliveries()->pluck('id')->all();
            }

            $payload = [
                'id' => $event->id,
                'type' => $event->event_type->value,
                'schema_version' => $event->schema_version,
                'occurred_at' => $event->occurred_at->toISOString(),
                'team_id' => $event->team_id,
                'data' => $event->payload,
            ];

            $deliveryIds = WebhookEndpoint::query()
                ->where('team_id', $event->team_id)
                ->whereHas('integration', fn ($query) => $query->where('status', IntegrationStatus::Active->value))
                ->get()
                ->filter(fn (WebhookEndpoint $endpoint) => app(WebhookSubscriptionMatcher::class)
                    ->matches($endpoint, $event))
                ->map(function (WebhookEndpoint $endpoint) use ($event, $payload) {
                    return WebhookDelivery::firstOrCreate(
                        [
                            'webhook_event_id' => $event->id,
                            'webhook_endpoint_id' => $endpoint->id,
                        ],
                        [
                            'team_id' => $event->team_id,
                            'status' => WebhookDeliveryStatus::Pending,
                            'payload_snapshot' => $payload,
                        ],
                    )->id;
                })
                ->values()
                ->all();

            $event->update(['published_at' => now()]);

            return $deliveryIds;
        });

        foreach ($deliveryIds as $deliveryId) {
            DeliverWebhook::dispatch($deliveryId)->onQueue('webhooks');
        }
    }
}
