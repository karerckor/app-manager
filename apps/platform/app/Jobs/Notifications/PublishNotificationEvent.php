<?php

namespace App\Jobs\Notifications;

use App\Enums\IntegrationStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Integrations\Notifications\NotificationEventCatalog;
use App\Integrations\Notifications\NotificationTemplateRenderer;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class PublishNotificationEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $eventId) {}

    public function handle(NotificationTemplateRenderer $renderer): void
    {
        if (! config('integrations.enabled') || ! config('integrations.notifications.enabled')) {
            return;
        }

        $deliveryIds = DB::transaction(function () use ($renderer) {
            $event = WebhookEvent::query()
                ->with('team')
                ->lockForUpdate()
                ->findOrFail($this->eventId);

            if ($event->notifications_published_at !== null) {
                return $event->notificationDeliveries()->pluck('id')->all();
            }

            if (! in_array($event->event_type->value, NotificationEventCatalog::values(), true)) {
                $event->update(['notifications_published_at' => now()]);

                return [];
            }

            $deliveryIds = NotificationDestination::query()
                ->with('integration')
                ->where('team_id', $event->team_id)
                ->whereHas('integration', fn ($query) => $query
                    ->where('status', IntegrationStatus::Active->value))
                ->get()
                ->filter(fn (NotificationDestination $destination) => in_array(
                    $event->event_type->value,
                    $destination->subscribed_events ?? [],
                    true,
                ))
                ->map(function (NotificationDestination $destination) use ($event, $renderer) {
                    return NotificationDelivery::firstOrCreate(
                        [
                            'webhook_event_id' => $event->id,
                            'notification_destination_id' => $destination->id,
                        ],
                        [
                            'team_id' => $event->team_id,
                            'status' => NotificationDeliveryStatus::Pending,
                            'rendered_message' => $renderer->render($event, $destination),
                        ],
                    )->id;
                })
                ->values()
                ->all();

            $event->update(['notifications_published_at' => now()]);

            return $deliveryIds;
        });

        foreach ($deliveryIds as $deliveryId) {
            DeliverNotification::dispatch($deliveryId);
        }
    }
}
