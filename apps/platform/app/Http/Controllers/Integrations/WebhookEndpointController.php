<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\WebhookEventType;
use App\Http\Controllers\Controller;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookSubscriptionFilters;
use App\Integrations\Webhooks\WebhookUrlGuard;
use App\Models\Integration;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use App\Rules\PublicWebhookUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebhookEndpointController extends Controller
{
    public function __construct(
        private readonly WebhookUrlGuard $urlGuard,
        private readonly WebhookEventRecorder $events,
    ) {}

    public function store(Request $request, Team $team): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $data = $request->validate($this->rules($team));
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
                'filters' => WebhookSubscriptionFilters::normalize($data['filters'] ?? []),
            ]);

            $integration->update(['external_id' => $endpoint->id]);

            return $endpoint;
        });

        return back()
            ->with('status', 'Webhook endpoint created.')
            ->with('webhook_secret', ['endpoint_id' => $endpoint->id, 'secret' => $secret]);
    }

    public function update(
        Request $request,
        Team $team,
        WebhookEndpoint $webhookEndpoint,
    ): RedirectResponse {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $this->authorizeEndpoint($team, $webhookEndpoint);
        $data = $request->validate($this->rules($team, updating: true, webhookEndpoint: $webhookEndpoint));

        DB::transaction(function () use ($webhookEndpoint, $request, $data) {
            $webhookEndpoint->update(array_filter([
                'url' => $data['url'] ?? null,
                'subscribed_events' => isset($data['events'])
                    ? array_values(array_unique($data['events']))
                    : null,
                'filters' => array_key_exists('filters', $data)
                    ? WebhookSubscriptionFilters::normalize($data['filters'])
                    : null,
            ], fn ($value) => $value !== null));

            $updates = [
                'updated_by_user_id' => $request->user()->id,
            ];

            if (isset($data['name'])) {
                $updates['name'] = $data['name'];
            }

            if (array_key_exists('enabled', $data)) {
                $updates['status'] = $data['enabled']
                    ? IntegrationStatus::Active
                    : IntegrationStatus::Disabled;
                $updates['disabled_at'] = $data['enabled'] ? null : now();
            }

            $webhookEndpoint->integration->update($updates);
        });

        return back()->with('status', 'Webhook endpoint updated.');
    }

    public function rotateSecret(
        Request $request,
        Team $team,
        WebhookEndpoint $webhookEndpoint,
    ): RedirectResponse {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $this->authorizeEndpoint($team, $webhookEndpoint);
        $secret = $this->newSecret();
        $webhookEndpoint->update([
            'secret' => $secret,
            'secret_hint' => substr($secret, -4),
        ]);

        return back()
            ->with('status', 'Webhook signing secret rotated.')
            ->with('webhook_secret', ['endpoint_id' => $webhookEndpoint->id, 'secret' => $secret]);
    }

    public function test(
        Request $request,
        Team $team,
        WebhookEndpoint $webhookEndpoint,
    ): RedirectResponse {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $this->authorizeEndpoint($team, $webhookEndpoint);

        DB::transaction(fn () => $this->events->record(
            $team,
            WebhookEventType::WebhookTest,
            $webhookEndpoint,
            ['endpoint_id' => $webhookEndpoint->id],
        ));

        return back()->with('status', 'Test webhook queued.');
    }

    public function destroy(
        Request $request,
        Team $team,
        WebhookEndpoint $webhookEndpoint,
    ): RedirectResponse {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $this->authorizeEndpoint($team, $webhookEndpoint);

        DB::transaction(function () use ($webhookEndpoint, $request) {
            $webhookEndpoint->integration->update([
                'status' => IntegrationStatus::Disconnected,
                'disconnected_at' => now(),
                'updated_by_user_id' => $request->user()->id,
            ]);
            $webhookEndpoint->delete();
        });

        return back()->with('status', 'Webhook endpoint deleted.');
    }

    /** @return array<string, mixed> */
    private function rules(Team $team, bool $updating = false, ?WebhookEndpoint $webhookEndpoint = null): array
    {
        $required = $updating ? 'sometimes' : 'required';

        // Updating must not strip an event this endpoint already subscribes
        // to just because the profile stopped offering it — otherwise an
        // endpoint that predates a profile switch becomes unsavable.
        // Creation is not given this allowance: it always validates against
        // what is subscribable right now.
        $subscribable = $updating
            ? array_values(array_unique(array_merge(
                WebhookEventType::subscribableValues(),
                $webhookEndpoint?->subscribed_events ?? [],
            )))
            : WebhookEventType::subscribableValues();

        return array_merge([
            'name' => [$required, 'string', 'max:255'],
            'url' => [$required, 'string', 'max:2048', new PublicWebhookUrl($this->urlGuard)],
            'events' => [$required, 'array', 'min:1'],
            'events.*' => ['string', 'distinct', Rule::in($subscribable)],
            'enabled' => ['sometimes', 'boolean'],
        ], WebhookSubscriptionFilters::rules($team));
    }

    private function authorizeEndpoint(Team $team, WebhookEndpoint $endpoint): void
    {
        abort_unless($endpoint->team_id === $team->id, 404);
    }

    private function newSecret(): string
    {
        return 'whsec_'.Str::random(48);
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('integrations.enabled') && config('integrations.webhooks.enabled'), 404);
    }
}
