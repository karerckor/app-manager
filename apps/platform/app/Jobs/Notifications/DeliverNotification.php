<?php

namespace App\Jobs\Notifications;

use App\Enums\IntegrationStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Integrations\Notifications\NotificationDeliveryException;
use App\Integrations\Notifications\NotificationSender;
use App\Models\NotificationDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(NotificationSender $sender): void
    {
        if (! config('integrations.enabled') || ! config('integrations.notifications.enabled')) {
            return;
        }

        $delivery = DB::transaction(function () {
            $delivery = NotificationDelivery::query()
                ->with(['team', 'destination.integration', 'event'])
                ->lockForUpdate()
                ->findOrFail($this->deliveryId);

            if (in_array($delivery->status, [
                NotificationDeliveryStatus::Delivered,
                NotificationDeliveryStatus::Exhausted,
            ], true)) {
                return null;
            }

            if ($delivery->status === NotificationDeliveryStatus::Delivering
                && $delivery->updated_at->isAfter(now()->subMinutes(15))) {
                return null;
            }

            if (! $delivery->team || $delivery->destination->trashed()
                || $delivery->destination->integration->status !== IntegrationStatus::Active) {
                $delivery->update([
                    'status' => NotificationDeliveryStatus::Exhausted,
                    'last_error' => 'The notification destination is disabled or deleted.',
                    'exhausted_at' => now(),
                ]);

                return null;
            }

            $delivery->update([
                'status' => NotificationDeliveryStatus::Delivering,
                'attempt_count' => $delivery->attempt_count + 1,
                'next_attempt_at' => null,
                'last_error' => null,
            ]);

            return $delivery->refresh()->load(['team', 'destination.integration', 'event']);
        });

        if (! $delivery) {
            return;
        }

        try {
            $sender->send($delivery->destination, $delivery->rendered_message);
            $delivery->update([
                'status' => NotificationDeliveryStatus::Delivered,
                'delivered_at' => now(),
            ]);
            $delivery->destination->update([
                'consecutive_failures' => 0,
                'last_delivered_at' => now(),
            ]);
        } catch (NotificationDeliveryException $exception) {
            $this->retryOrExhaust($delivery, $exception->getMessage());
        }
    }

    private function retryOrExhaust(NotificationDelivery $delivery, string $error): void
    {
        $delays = config('integrations.notifications.retry_delays', []);
        $delay = $delays[$delivery->attempt_count - 1] ?? null;
        $delivery->destination->increment('consecutive_failures');

        if ($delay === null) {
            $delivery->update([
                'status' => NotificationDeliveryStatus::Exhausted,
                'last_error' => mb_substr($error, 0, 4096),
                'exhausted_at' => now(),
                'next_attempt_at' => null,
            ]);

            return;
        }

        $delivery->update([
            'status' => NotificationDeliveryStatus::RetryScheduled,
            'last_error' => mb_substr($error, 0, 4096),
            'next_attempt_at' => now()->addSeconds($delay),
        ]);

        self::dispatch($delivery->id)->delay($delay)->onQueue('notifications');
    }
}
