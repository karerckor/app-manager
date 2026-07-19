<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Integrations\RedeliverWebhook;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebhookDeliveryResource;
use App\Models\Team;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WebhookDeliveryController extends Controller
{
    use ResolvesPageSize;

    public function index(Request $request, Team $team, WebhookEndpoint $webhookEndpoint): AnonymousResourceCollection
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);

        return WebhookDeliveryResource::collection(
            $webhookEndpoint->deliveries()->with('event')->latest()->cursorPaginate($this->perPage($request)),
        );
    }

    public function show(Team $team, WebhookEndpoint $webhookEndpoint, WebhookDelivery $delivery): WebhookDeliveryResource
    {
        $this->ensureEnabled();
        $this->assertDelivery($team, $webhookEndpoint, $delivery);

        return new WebhookDeliveryResource($delivery->load('event'));
    }

    public function redeliver(Team $team, WebhookEndpoint $webhookEndpoint, WebhookDelivery $delivery, RedeliverWebhook $redeliver): JsonResponse
    {
        $this->ensureEnabled();
        $this->assertDelivery($team, $webhookEndpoint, $delivery);
        $new = $redeliver->handle($delivery);

        return (new WebhookDeliveryResource($new->load('event')))->response()->setStatusCode(201);
    }

    protected function assertEndpoint(Team $team, WebhookEndpoint $endpoint): void
    {
        abort_unless($endpoint->team_id === $team->id, 404);
    }

    protected function assertDelivery(Team $team, WebhookEndpoint $endpoint, WebhookDelivery $delivery): void
    {
        $this->assertEndpoint($team, $endpoint);
        abort_unless($delivery->webhook_endpoint_id === $endpoint->id && $delivery->team_id === $team->id, 404);
    }

    protected function ensureEnabled(): void
    {
        abort_unless(config('integrations.enabled') && config('integrations.webhooks.enabled'), 404);
    }
}
