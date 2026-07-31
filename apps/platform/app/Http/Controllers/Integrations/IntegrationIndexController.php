<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\ProfileType;
use App\Enums\WebhookEventType;
use App\Http\Controllers\Controller;
use App\Integrations\Webhooks\WebhookSubscriptionFilters;
use App\Models\ExpoProject;
use App\Models\Integration;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationIndexController extends Controller
{
    private const EXPO_PROJECTS_PER_PAGE = 20;

    public function ciCd(Request $request, Team $team): Response
    {
        return $this->render($request, $team, 'ci_cd');
    }

    public function eas(Request $request, Team $team): Response
    {
        return $this->render($request, $team, 'expo');
    }

    public function webhooks(Request $request, Team $team): Response
    {
        return $this->render($request, $team, 'webhooks');
    }

    private function render(Request $request, Team $team, string $activeTab): Response
    {
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $connectedCiCdIntegrations = $team->integrations()
            ->where('kind', IntegrationKind::CiCd->value)
            ->where('status', IntegrationStatus::Active->value)
            ->get(['provider', 'settings']);

        return Inertia::render('Integrations/Index', [
            'ciCdProviders' => collect([IntegrationProvider::GitHub, IntegrationProvider::GitLab])
                ->map(fn (IntegrationProvider $provider) => [
                    'provider' => $provider->value,
                    'name' => $provider === IntegrationProvider::GitHub ? 'GitHub Actions' : 'GitLab CI/CD',
                    'description' => $provider === IntegrationProvider::GitHub
                        ? 'Discover workflow_dispatch workflows and run builds.'
                        : 'Discover projects, run pipelines, and synchronize job logs.',
                    'enabled' => (bool) config('integrations.enabled')
                        && (bool) config("integrations.{$provider->value}.enabled"),
                    'connected_accounts_count' => $connectedCiCdIntegrations
                        ->filter(fn ($integration) => $integration->provider === $provider)
                        ->when(
                            $provider === IntegrationProvider::GitLab,
                            fn ($integrations) => $integrations->reject(
                                fn ($integration) => data_get(
                                    $integration->settings,
                                    'connection_type',
                                ) === 'self_hosted',
                            ),
                        )
                        ->count(),
                    'connect_url' => route("accounts.integrations.{$provider->value}.connect", $team),
                ]),
            'selfHostedGitLab' => [
                'enabled' => (bool) config('integrations.enabled')
                    && (bool) config('integrations.gitlab.self_hosted_enabled'),
                'connected_accounts_count' => $connectedCiCdIntegrations
                    ->filter(fn ($integration) => $integration->provider === IntegrationProvider::GitLab
                        && data_get($integration->settings, 'connection_type') === 'self_hosted')
                    ->count(),
                'store_url' => route('accounts.integrations.gitlab.self-hosted.store', $team),
            ],
            'expoIntegration' => [
                'enabled' => (bool) config('integrations.enabled')
                    && (bool) config('integrations.expo.enabled'),
                'index_url' => route('accounts.integrations.eas.index', $team),
                'search' => trim((string) $request->query('expo_search', '')),
                'store_url' => route('accounts.integrations.expo.store', $team),
                'accounts' => $team->integrations()
                    ->connectedExpo()
                    ->latest()
                    ->get()
                    ->map(fn (Integration $integration) => $this->presentExpoAccount(
                        $request,
                        $team,
                        $integration,
                    ))
                    ->values(),
            ],
            'activeTab' => $activeTab,
            'integrationUrls' => [
                'ci_cd' => route('accounts.integrations.index', $team),
                'expo' => route('accounts.integrations.eas.index', $team),
                'webhooks' => route('accounts.integrations.webhooks.index', $team),
            ],
            'storeWebhookUrl' => route('accounts.integrations.webhooks.store', $team),
            'webhooksEnabled' => (bool) config('integrations.enabled')
                && (bool) config('integrations.webhooks.enabled'),
            'webhookEventTypes' => WebhookEventType::subscribableValues(),
            'newWebhookSecret' => $request->session()->pull('webhook_secret'),
            'profileTypes' => array_map(fn (ProfileType $type) => $type->value, ProfileType::cases()),
            'buildStatuses' => array_map(fn (BuildStatus $status) => $status->value, BuildStatus::cases()),
            'reportingModes' => array_map(fn (BuildReportingMode $mode) => $mode->value, BuildReportingMode::cases()),
            'apps' => $team->mobileApps()
                ->with(['appIdentifiers', 'expoProject'])
                ->orderBy('name')
                ->get()
                ->map(fn ($app) => [
                    'id' => $app->id,
                    'name' => $app->name,
                    'tags' => collect($app->tagList())->pluck(0)->values(),
                    'expo_project_id' => $app->expoProject?->external_id,
                    'identifiers' => $app->appIdentifiers->map(fn ($identifier) => [
                        'id' => $identifier->id,
                        'name' => $identifier->name ?: $identifier->identifier,
                        'platform' => $identifier->platform->value,
                    ]),
                ]),
            'integrations' => $team->integrations()
                ->where(fn ($query) => $query
                    ->where('kind', IntegrationKind::CiCd->value)
                    ->orWhere(fn ($query) => $query
                        ->where('kind', IntegrationKind::AppService->value)
                        ->where('provider', IntegrationProvider::Expo->value)
                        ->whereHas('oauthCredential')
                        ->whereHas('expoProjects', fn ($query) => $query
                            ->whereNotNull('mobile_app_id')
                            ->where('exists_on_expo', true))))
                ->with([
                    'pipelines.mobileApp',
                    'pipelines.appIdentifier',
                    'expoProjects' => fn ($query) => $query
                        ->whereNotNull('mobile_app_id')
                        ->where('exists_on_expo', true),
                ])
                ->withCount('pipelines')
                ->latest()
                ->get()
                ->map(fn ($integration) => [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'provider' => $integration->provider->value,
                    'provider_name' => $integration->provider === IntegrationProvider::Expo
                        ? 'Expo EAS'
                        : $integration->provider->label(),
                    'status' => $integration->status->value,
                    'account_name' => $integration->external_account_name,
                    'connection_type' => data_get($integration->settings, 'connection_type', 'oauth'),
                    'instance_url' => data_get($integration->settings, 'gitlab_web_url'),
                    'pipelines_count' => $integration->pipelines_count,
                    'eligible_app_ids' => $integration->provider === IntegrationProvider::Expo
                        ? $integration->expoProjects->pluck('mobile_app_id')->values()
                        : $team->mobileApps()->pluck('id'),
                    'configuration_url' => $integration->provider === IntegrationProvider::Expo
                        ? route('accounts.integrations.eas.index', $team)
                        : null,
                    'last_synced_at' => $integration->last_synced_at,
                    'repositories_url' => route('accounts.integrations.repositories.index', [$team, $integration]),
                    'workflows_url' => route('accounts.integrations.workflows.index', [$team, $integration]),
                    'refs_url' => route('accounts.integrations.refs.index', [$team, $integration]),
                    'pipelines_store_url' => route('accounts.integrations.pipelines.store', [$team, $integration]),
                    'pipelines_batch_rename_url' => route('accounts.integrations.pipelines.batch.rename', [$team, $integration]),
                    'pipelines_batch_destroy_url' => route('accounts.integrations.pipelines.batch.destroy', [$team, $integration]),
                    'pipelines_batch_run_url' => route('accounts.integrations.pipelines.batch.runs.store', [$team, $integration]),
                    'sync_url' => route("accounts.integrations.{$integration->provider->value}.sync", [$team, $integration]),
                    'disconnect_url' => route("accounts.integrations.{$integration->provider->value}.disconnect", [$team, $integration]),
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
                    'filters' => WebhookSubscriptionFilters::normalize($endpoint->filters),
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
                        'duration_ms' => $delivery->duration_ms,
                        'next_attempt_at' => $delivery->next_attempt_at,
                        'inspect_url' => route(
                            'accounts.integrations.webhooks.deliveries.show',
                            [$team, $endpoint, $delivery],
                        ),
                        'created_at' => $delivery->created_at,
                    ]),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentExpoAccount(
        Request $request,
        Team $team,
        Integration $integration,
    ): array {
        $requestedIntegration = (string) $request->query('expo_integration', '');
        $requestedPage = $requestedIntegration === $integration->id
            ? max(1, $request->integer('expo_page', 1))
            : 1;
        $search = trim((string) $request->query('expo_search', ''));
        $projects = $integration->expoProjects()
            ->with('mobileApp')
            ->when($search !== '', fn ($query) => $query->where(
                fn ($query) => $query
                    ->whereLike('name', "%{$search}%")
                    ->orWhereLike('slug', "%{$search}%")
                    ->orWhereLike('external_id', "%{$search}%"),
            ))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(
                self::EXPO_PROJECTS_PER_PAGE,
                ['*'],
                'expo_page',
                $requestedPage,
            );

        if ($projects->total() > 0 && $requestedPage > $projects->lastPage()) {
            $projects = $integration->expoProjects()
                ->with('mobileApp')
                ->when($search !== '', fn ($query) => $query->where(
                    fn ($query) => $query
                        ->whereLike('name', "%{$search}%")
                        ->orWhereLike('slug', "%{$search}%")
                        ->orWhereLike('external_id', "%{$search}%"),
                ))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate(
                    self::EXPO_PROJECTS_PER_PAGE,
                    ['*'],
                    'expo_page',
                    $projects->lastPage(),
                );
        }

        $pageUrl = fn (int $page) => route('accounts.integrations.eas.index', array_filter([
            $team,
            'expo_search' => $search !== '' ? $search : null,
            'expo_integration' => $integration->id,
            'expo_page' => $page,
        ], fn ($value) => $value !== null));

        return [
            'id' => $integration->id,
            'name' => $integration->name,
            'account_name' => $integration->external_account_name,
            'status' => $integration->status->value,
            'last_synced_at' => $integration->last_synced_at,
            'last_sync_error' => data_get($integration->settings, 'last_sync_error'),
            'last_sync_failed_at' => data_get($integration->settings, 'last_sync_failed_at'),
            'sync_url' => route('accounts.integrations.expo.sync', [$team, $integration]),
            'disconnect_url' => route('accounts.integrations.expo.disconnect', [$team, $integration]),
            'bulk_import_url' => route('accounts.integrations.expo.projects.import-batch', [$team, $integration]),
            'projects' => $projects->getCollection()
                ->map(fn (ExpoProject $project) => [
                    'id' => $project->id,
                    'project_id' => $project->external_id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'full_name' => $project->full_name,
                    'icon_url' => $project->icon_url,
                    'exists_on_expo' => $project->exists_on_expo,
                    'app_name' => $project->mobileApp?->name,
                    'app_url' => $project->mobileApp
                        ? route('accounts.apps.expo.index', [$team, $project->mobileApp])
                        : null,
                    'import_url' => route(
                        'accounts.integrations.expo.projects.import',
                        [$team, $integration, $project],
                    ),
                ])
                ->values(),
            'pagination' => [
                'page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'from' => $projects->firstItem(),
                'to' => $projects->lastItem(),
                'total' => $projects->total(),
                'previous_url' => $projects->currentPage() > 1
                    ? $pageUrl($projects->currentPage() - 1)
                    : null,
                'next_url' => $projects->hasMorePages()
                    ? $pageUrl($projects->currentPage() + 1)
                    : null,
            ],
        ];
    }
}
