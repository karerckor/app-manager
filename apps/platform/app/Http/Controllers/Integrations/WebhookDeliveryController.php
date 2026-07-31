<?php

namespace App\Http\Controllers\Integrations;

use App\Actions\Integrations\RedeliverWebhook;
use App\Enums\WebhookDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WebhookDeliveryController extends Controller
{
    public function show(
        Request $request,
        Team $team,
        WebhookEndpoint $webhookEndpoint,
        WebhookDelivery $delivery,
    ): JsonResponse {
        $this->authorizeDelivery($request, $team, $webhookEndpoint, $delivery);
        $delivery->load(['event', 'redeliveryOf']);

        return response()->json([
            'data' => [
                'id' => $delivery->id,
                'event_id' => $delivery->webhook_event_id,
                'event_type' => $delivery->event?->event_type?->value,
                'schema_version' => $delivery->event?->schema_version,
                'status' => $delivery->status->value,
                'attempt_count' => $delivery->attempt_count,
                'signature_version' => $delivery->signature_version,
                'payload' => $delivery->payload_snapshot,
                'endpoint_url' => $webhookEndpoint->url,
                'response_status' => $delivery->response_status,
                'duration_ms' => $delivery->duration_ms,
                'response_body' => $delivery->response_body,
                'last_error' => $delivery->last_error,
                'next_attempt_at' => $delivery->next_attempt_at,
                'delivered_at' => $delivery->delivered_at,
                'exhausted_at' => $delivery->exhausted_at,
                'redelivery_of_id' => $delivery->redelivery_of_id,
                'created_at' => $delivery->created_at,
                'updated_at' => $delivery->updated_at,
                'redeliver_url' => route(
                    'accounts.integrations.webhooks.deliveries.redeliver',
                    [$team, $webhookEndpoint, $delivery],
                ),
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function redeliver(
        Request $request,
        Team $team,
        WebhookEndpoint $webhookEndpoint,
        WebhookDelivery $delivery,
        RedeliverWebhook $redeliver,
    ): RedirectResponse {
        $this->authorizeDelivery($request, $team, $webhookEndpoint, $delivery);
        abort_unless(in_array($delivery->status, [
            WebhookDeliveryStatus::Delivered,
            WebhookDeliveryStatus::Exhausted,
        ], true), 409, 'Only terminal webhook deliveries can be redelivered.');
        $new = $redeliver->handle($delivery);

        return back()
            ->with('status', 'Webhook redelivery queued.')
            ->with('webhook_redelivery_id', $new->id);
    }

    private function authorizeDelivery(
        Request $request,
        Team $team,
        WebhookEndpoint $endpoint,
        WebhookDelivery $delivery,
    ): void {
        abort_unless(config('integrations.enabled') && config('integrations.webhooks.enabled'), 404);
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        abort_unless(
            $endpoint->team_id === $team->id
                && $delivery->team_id === $team->id
                && $delivery->webhook_endpoint_id === $endpoint->id,
            404,
        );
    }
}
