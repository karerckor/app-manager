<?php

namespace App\Jobs\Webhooks;

use App\Enums\IntegrationStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Integrations\Webhooks\WebhookUrlGuard;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId) {}

    /** @throws JsonException */
    public function handle(WebhookUrlGuard $urlGuard): void
    {
        if (! config('integrations.enabled') || ! config('integrations.webhooks.enabled')) {
            return;
        }

        $delivery = DB::transaction(function () {
            $delivery = WebhookDelivery::query()
                ->with(['team', 'endpoint.integration', 'event'])
                ->lockForUpdate()
                ->findOrFail($this->deliveryId);

            if (in_array($delivery->status, [
                WebhookDeliveryStatus::Delivered,
                WebhookDeliveryStatus::Exhausted,
            ], true)) {
                return null;
            }

            if ($delivery->status === WebhookDeliveryStatus::Delivering
                && $delivery->updated_at->isAfter(now()->subMinutes(15))) {
                return null;
            }

            if (! $delivery->team || $delivery->endpoint->trashed()
                || $delivery->endpoint->integration->status !== IntegrationStatus::Active) {
                $delivery->update([
                    'status' => WebhookDeliveryStatus::Exhausted,
                    'last_error' => 'The webhook endpoint is disabled or deleted.',
                    'exhausted_at' => now(),
                ]);

                return null;
            }

            $delivery->update([
                'status' => WebhookDeliveryStatus::Delivering,
                'attempt_count' => $delivery->attempt_count + 1,
                'next_attempt_at' => null,
                'last_error' => null,
            ]);

            return $delivery->refresh()->load(['team', 'endpoint.integration', 'event']);
        });

        if (! $delivery) {
            return;
        }

        $body = json_encode(
            $delivery->payload_snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $delivery->endpoint->secret);
        $startedAt = hrtime(true);

        try {
            $urlGuard->assertSafe($delivery->endpoint->url);
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => 'AppManager-Webhooks/1.0',
                'X-App-Manager-Event' => $delivery->event->event_type->value,
                'X-App-Manager-Delivery' => $delivery->id,
                'X-App-Manager-Timestamp' => $timestamp,
                'X-App-Manager-Signature' => 'v1='.$signature,
            ])
                ->connectTimeout(config('webhooks.connect_timeout'))
                ->timeout(config('webhooks.timeout'))
                ->withoutRedirecting()
                ->withBody($body, 'application/json')
                ->post($delivery->endpoint->url);

            $duration = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $responseBody = mb_substr($response->body(), 0, (int) config('webhooks.response_body_limit'));

            if ($response->successful()) {
                $delivery->update([
                    'status' => WebhookDeliveryStatus::Delivered,
                    'response_status' => $response->status(),
                    'duration_ms' => $duration,
                    'response_body' => $responseBody,
                    'delivered_at' => now(),
                ]);
                $delivery->endpoint->update([
                    'consecutive_failures' => 0,
                    'last_delivered_at' => now(),
                ]);

                return;
            }

            if ($response->status() === 410) {
                $delivery->endpoint->integration->update([
                    'status' => IntegrationStatus::Disabled,
                    'disabled_at' => now(),
                ]);
                $this->exhaust($delivery, 'The endpoint returned HTTP 410.', $response->status(), $duration, $responseBody);

                return;
            }

            $this->retryOrExhaust($delivery, 'The endpoint returned HTTP '.$response->status().'.', $response->status(), $duration, $responseBody);
        } catch (Throwable $exception) {
            $duration = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            $this->retryOrExhaust($delivery, $exception->getMessage(), null, $duration);
        }
    }

    private function retryOrExhaust(
        WebhookDelivery $delivery,
        string $error,
        ?int $responseStatus,
        int $duration,
        ?string $responseBody = null,
    ): void {
        $delays = config('webhooks.retry_delays');
        $delay = $delays[$delivery->attempt_count - 1] ?? null;
        $delivery->endpoint->increment('consecutive_failures');

        if ($delay === null) {
            $this->exhaust($delivery, $error, $responseStatus, $duration, $responseBody);

            return;
        }

        $delivery->update([
            'status' => WebhookDeliveryStatus::RetryScheduled,
            'response_status' => $responseStatus,
            'duration_ms' => $duration,
            'response_body' => $responseBody,
            'last_error' => mb_substr($error, 0, 4096),
            'next_attempt_at' => now()->addSeconds($delay),
        ]);

        self::dispatch($delivery->id)->delay($delay);
    }

    private function exhaust(
        WebhookDelivery $delivery,
        string $error,
        ?int $responseStatus,
        int $duration,
        ?string $responseBody = null,
    ): void {
        $delivery->update([
            'status' => WebhookDeliveryStatus::Exhausted,
            'response_status' => $responseStatus,
            'duration_ms' => $duration,
            'response_body' => $responseBody,
            'last_error' => mb_substr($error, 0, 4096),
            'exhausted_at' => now(),
            'next_attempt_at' => null,
        ]);
    }
}
