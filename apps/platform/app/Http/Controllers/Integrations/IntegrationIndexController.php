<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\BuildReportingMode;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\ProfileType;
use App\Enums\WebhookEventType;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationIndexController extends Controller
{
    public function __invoke(Request $request, Team $team): Response
    {
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);

        return Inertia::render('Integrations/Index', [
            'githubEnabled' => (bool) config('integrations.enabled')
                && (bool) config('integrations.github.enabled'),
            'connectGitHubUrl' => route('accounts.integrations.github.connect', $team),
            'storeWebhookUrl' => route('accounts.integrations.webhooks.store', $team),
            'webhooksEnabled' => (bool) config('integrations.enabled')
                && (bool) config('integrations.webhooks.enabled'),
            'webhookEventTypes' => WebhookEventType::subscribableValues(),
            'newWebhookSecret' => $request->session()->pull('webhook_secret'),
            'profileTypes' => array_map(fn (ProfileType $type) => $type->value, ProfileType::cases()),
            'reportingModes' => array_map(fn (BuildReportingMode $mode) => $mode->value, BuildReportingMode::cases()),
            'apps' => $team->mobileApps()
                ->with('appIdentifiers')
                ->orderBy('name')
                ->get()
                ->map(fn ($app) => [
                    'id' => $app->id,
                    'name' => $app->name,
                    'identifiers' => $app->appIdentifiers->map(fn ($identifier) => [
                        'id' => $identifier->id,
                        'name' => $identifier->name ?: $identifier->identifier,
                        'platform' => $identifier->platform->value,
                    ]),
                ]),
            'integrations' => $team->integrations()
                ->where('kind', IntegrationKind::CiCd->value)
                ->with(['pipelines.mobileApp', 'pipelines.appIdentifier'])
                ->withCount('pipelines')
                ->latest()
                ->get()
                ->map(fn ($integration) => [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'provider' => $integration->provider->value,
                    'status' => $integration->status->value,
                    'account_name' => $integration->external_account_name,
                    'pipelines_count' => $integration->pipelines_count,
                    'last_synced_at' => $integration->last_synced_at,
                    'repositories_url' => route('accounts.integrations.repositories.index', [$team, $integration]),
                    'workflows_url' => route('accounts.integrations.workflows.index', [$team, $integration]),
                    'refs_url' => route('accounts.integrations.refs.index', [$team, $integration]),
                    'pipelines_store_url' => route('accounts.integrations.pipelines.store', [$team, $integration]),
                    'sync_url' => $integration->provider === IntegrationProvider::GitHub
                        ? route('accounts.integrations.github.sync', [$team, $integration])
                        : null,
                    'disconnect_url' => $integration->provider === IntegrationProvider::GitHub
                        ? route('accounts.integrations.github.disconnect', [$team, $integration])
                        : null,
                    'pipelines' => $integration->pipelines->map(fn ($pipeline) => [
                        'id' => $pipeline->id,
                        'name' => $pipeline->name,
                        'enabled' => $pipeline->enabled,
                        'mobile_app_id' => $pipeline->mobile_app_id,
                        'app_identifier_id' => $pipeline->app_identifier_id,
                        'app_name' => $pipeline->mobileApp->name,
                        'target_name' => $pipeline->appIdentifier?->name ?: $pipeline->appIdentifier?->identifier,
                        'profile_type' => $pipeline->profile_type->value,
                        'reporting_mode' => $pipeline->reporting_mode->value,
                        'build_id_input' => $pipeline->build_id_input,
                        'repository_full_name' => $pipeline->repository_full_name,
                        'workflow_name' => $pipeline->workflow_name,
                        'workflow_id' => $pipeline->workflow_id,
                        'default_ref' => $pipeline->default_ref,
                        'input_schema' => $pipeline->input_schema ?? [],
                        'input_defaults' => $pipeline->input_defaults ?? [],
                        'run_url' => route('accounts.integrations.pipelines.runs.store', [$team, $integration, $pipeline]),
                        'update_url' => route('accounts.integrations.pipelines.update', [$team, $integration, $pipeline]),
                        'rename_url' => route('accounts.integrations.pipelines.rename', [$team, $integration, $pipeline]),
                        'destroy_url' => route('accounts.integrations.pipelines.destroy', [$team, $integration, $pipeline]),
                    ]),
                ]),
            'webhookEndpoints' => $team->webhookEndpoints()
                ->with(['integration', 'deliveries' => fn ($query) => $query->with('event')->latest()->limit(10)])
                ->latest()
                ->get()
                ->map(fn ($endpoint) => [
                    'id' => $endpoint->id,
                    'name' => $endpoint->integration->name,
                    'url' => $endpoint->url,
                    'status' => $endpoint->integration->status->value,
                    'secret_hint' => $endpoint->secret_hint,
                    'events' => $endpoint->subscribed_events,
                    'last_delivered_at' => $endpoint->last_delivered_at,
                    'consecutive_failures' => $endpoint->consecutive_failures,
                    'update_url' => route('accounts.integrations.webhooks.update', [$team, $endpoint]),
                    'rotate_secret_url' => route('accounts.integrations.webhooks.rotate-secret', [$team, $endpoint]),
                    'test_url' => route('accounts.integrations.webhooks.test', [$team, $endpoint]),
                    'destroy_url' => route('accounts.integrations.webhooks.destroy', [$team, $endpoint]),
                    'deliveries' => $endpoint->deliveries->map(fn ($delivery) => [
                        'id' => $delivery->id,
                        'event' => $delivery->event?->event_type->value,
                        'status' => $delivery->status->value,
                        'attempt_count' => $delivery->attempt_count,
                        'response_status' => $delivery->response_status,
                        'created_at' => $delivery->created_at,
                    ]),
                ]),
        ]);
    }
}
