<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebhookEndpointResource;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WebhookEndpointController extends Controller
{
    use ResolvesPageSize;

    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        $this->ensureEnabled();

        return WebhookEndpointResource::collection(
            $team->webhookEndpoints()->with('integration')->latest()->paginate($this->perPage($request)),
        );
    }

    public function show(Team $team, WebhookEndpoint $webhookEndpoint): WebhookEndpointResource
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);

        return new WebhookEndpointResource($webhookEndpoint->load('integration'));
    }

    protected function assertEndpoint(Team $team, WebhookEndpoint $endpoint): void
    {
        abort_unless($endpoint->team_id === $team->id, 404);
    }

    protected function ensureEnabled(): void
    {
        abort_unless(config('integrations.enabled') && config('integrations.webhooks.enabled'), 404);
    }
}
