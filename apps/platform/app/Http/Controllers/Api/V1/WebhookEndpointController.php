<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\WebhookEventType;
use App\Http\Concerns\ResolvesPageSize;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WebhookEndpointResource;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookUrlGuard;
use App\Models\Integration;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use App\Rules\PublicWebhookUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebhookEndpointController extends Controller
{
    use ResolvesPageSize;

    public function __construct(
        private readonly WebhookUrlGuard $urlGuard,
        private readonly WebhookEventRecorder $events,
    ) {}

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

    public function store(Request $request, Team $team): JsonResponse
    {
        $this->ensureEnabled();
        $data = $request->validate($this->rules());
        $secret = $this->newSecret();

        $endpoint = DB::transaction(function () use ($team, $request, $data, $secret) {
            $integration = Integration::create([
                'team_id' => $team->id,
                'kind' => IntegrationKind::Webhook,
                'provider' => IntegrationProvider::Http,
                'name' => $data['name'],
                'status' => IntegrationStatus::Active,
                'created_by_user_id' => $request->user()->id,
                'updated_by_user_id' => $request->user()->id,
            ]);
            $endpoint = WebhookEndpoint::create([
                'team_id' => $team->id,
                'integration_id' => $integration->id,
                'url' => $data['url'],
                'secret' => $secret,
                'secret_hint' => substr($secret, -4),
                'subscribed_events' => array_values(array_unique($data['events'])),
            ]);
            $integration->update(['external_id' => $endpoint->id]);

            return $endpoint;
        });

        return (new WebhookEndpointResource($endpoint->load('integration')))
            ->additional(['data' => ['secret' => $secret]])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Team $team, WebhookEndpoint $webhookEndpoint): WebhookEndpointResource
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);
        $data = $request->validate($this->rules(updating: true));

        DB::transaction(function () use ($webhookEndpoint, $request, $data) {
            $webhookEndpoint->update(array_filter([
                'url' => $data['url'] ?? null,
                'subscribed_events' => isset($data['events']) ? array_values(array_unique($data['events'])) : null,
            ], fn ($value) => $value !== null));

            $updates = ['updated_by_user_id' => $request->user()->id];
            if (isset($data['name'])) {
                $updates['name'] = $data['name'];
            }
            if (array_key_exists('enabled', $data)) {
                $updates['status'] = $data['enabled'] ? IntegrationStatus::Active : IntegrationStatus::Disabled;
                $updates['disabled_at'] = $data['enabled'] ? null : now();
            }
            $webhookEndpoint->integration->update($updates);
        });

        return new WebhookEndpointResource($webhookEndpoint->fresh()->load('integration'));
    }

    public function rotateSecret(Team $team, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);
        $secret = $this->newSecret();
        $webhookEndpoint->update(['secret' => $secret, 'secret_hint' => substr($secret, -4)]);

        return (new WebhookEndpointResource($webhookEndpoint->load('integration')))
            ->additional(['data' => ['secret' => $secret]])
            ->response();
    }

    public function test(Request $request, Team $team, WebhookEndpoint $webhookEndpoint): JsonResponse
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);
        DB::transaction(fn () => $this->events->record(
            $team,
            WebhookEventType::WebhookTest,
            $webhookEndpoint,
            ['endpoint_id' => $webhookEndpoint->id],
            $request->user(),
        ));

        return response()->json(['data' => ['status' => 'queued']], 202);
    }

    public function destroy(Request $request, Team $team, WebhookEndpoint $webhookEndpoint): Response
    {
        $this->ensureEnabled();
        $this->assertEndpoint($team, $webhookEndpoint);
        DB::transaction(function () use ($webhookEndpoint, $request) {
            $webhookEndpoint->integration->update([
                'status' => IntegrationStatus::Disconnected,
                'disconnected_at' => now(),
                'updated_by_user_id' => $request->user()->id,
            ]);
            $webhookEndpoint->delete();
        });

        return response()->noContent();
    }

    protected function assertEndpoint(Team $team, WebhookEndpoint $endpoint): void
    {
        abort_unless($endpoint->team_id === $team->id, 404);
    }

    protected function ensureEnabled(): void
    {
        abort_unless(config('integrations.enabled') && config('integrations.webhooks.enabled'), 404);
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'url' => [$required, 'string', 'max:2048', new PublicWebhookUrl($this->urlGuard)],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => ['string', 'distinct', Rule::in(WebhookEventType::subscribableValues())],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    private function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }
}
