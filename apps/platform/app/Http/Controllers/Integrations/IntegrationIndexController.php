<?php

namespace App\Http\Controllers\Integrations;

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\IntegrationKind;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Enums\WebhookEventType;
use App\Http\Controllers\Controller;
use App\Integrations\Notifications\NotificationEventCatalog;
use App\Integrations\Webhooks\WebhookSubscriptionFilters;
use App\Models\CiCdPipeline;
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

    private const CI_CD_PIPELINES_PER_PAGE = 25;

    public function ciCd(Request $request, Team $team): Response
    {
        return $this->render($request, $team, 'ci_cd');
    }

    public function showCiCd(Request $request, Team $team, Integration $integration): Response
    {
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        abort_unless($integration->team_id === $team->id && $integration->supportsCiCd(), 404);

        $filters = $this->ciCdPipelineFilters($request);
        $integration->load([
            'expoProjects' => fn ($query) => $query
                ->whereNotNull('mobile_app_id')
                ->where('exists_on_expo', true),
        ])->loadCount('pipelines');
        $integration->setAttribute(
            'linked_apps_count',
            $integration->pipelines()->distinct()->count('mobile_app_id'),
        );

        $pipelines = $integration->pipelines()
            ->with([
                'mobileApp:id,name',
                'appIdentifier:id,mobile_app_id,name,identifier,platform',
            ])
            ->when($filters['search'] !== '', fn ($query) => $query->where(
                fn ($query) => $query
                    ->whereLike('name', "%{$filters['search']}%")
                    ->orWhereLike('repository_full_name', "%{$filters['search']}%")
                    ->orWhereLike('workflow_name', "%{$filters['search']}%")
                    ->orWhereHas('mobileApp', fn ($query) => $query
                        ->whereLike('name', "%{$filters['search']}%"))
                    ->orWhereHas('appIdentifier', fn ($query) => $query
                        ->whereLike('name', "%{$filters['search']}%")
                        ->orWhereLike('identifier', "%{$filters['search']}%")),
            ))
            ->when($filters['status'] !== '', fn ($query) => $query
                ->where('enabled', $filters['status'] === 'enabled'))
            ->when($filters['platform'] !== '', fn ($query) => $query
                ->whereHas('appIdentifier', fn ($query) => $query
                    ->where('platform', $filters['platform'])))
            ->when($filters['profile'] !== '', fn ($query) => $query
                ->where('profile_type', $filters['profile']))
            ->orderBy('workflow_name')
            ->orderBy('name')
            ->orderBy('mobile_app_id')
            ->orderBy('id')
            ->paginate(self::CI_CD_PIPELINES_PER_PAGE)
            ->withQueryString()
            ->through(fn (CiCdPipeline $pipeline) => $this->presentCiCdPipeline(
                $team,
                $integration,
                $pipeline,
            ));

        return Inertia::render('Integrations/Show', [
            'integration' => $this->presentCiCdIntegration($team, $integration, true),
            'pipelines' => $pipelines,
            'pipelineFilters' => $filters,
            'platforms' => array_map(fn (Platform $platform) => [
                'value' => $platform->value,
                'label' => match ($platform) {
                    Platform::Ios => 'iOS',
                    Platform::Android => 'Android',
                    Platform::Web => 'Web',
                },
            ], Platform::cases()),
            'apps' => Inertia::optional(fn () => $this->presentApps($team)),
            'profileTypes' => array_map(fn (ProfileType $type) => $type->value, ProfileType::cases()),
            'reportingModes' => array_map(fn (BuildReportingMode $mode) => $mode->value, BuildReportingMode::cases()),
        ]);
    }

    public function eas(Request $request, Team $team): Response
    {
        return $this->render($request, $team, 'expo');
    }

    public function webhooks(Request $request, Team $team): Response
    {
        return $this->render($request, $team, 'webhooks');
    }

    public function notifications(Request $request, Team $team): Response
    {
        return $this->render($request, $team, 'notifications');
    }

    public function sentry(Request $request, Team $team): Response
    {
        return $this->render($request, $team, 'sentry');
    }

    private function render(Request $request, Team $team, string $activeTab): Response
    {
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $connectedCiCdIntegrations = $team->integrations()
            ->where('kind', IntegrationKind::CiCd->value)
            ->where('status', IntegrationStatus::Active->value)
            ->get(['provider', 'settings']);
        $notificationDestinations = $team->notificationDestinations()
            ->with('integration')
            ->latest()
            ->get()
            ->map(fn ($destination) => [
                'id' => $destination->id,
                'name' => $destination->integration->name,
                'provider' => $destination->integration->provider->value,
                'provider_name' => $destination->integration->provider->label(),
                'destination' => $destination->destination,
                'configured' => filled($destination->credentials),
                'status' => $destination->integration->status->value,
                'last_delivered_at' => $destination->last_delivered_at,
                'events' => $destination->subscribed_events,
                'templates' => $destination->templates,
                'tags' => $destination->tags ?? [],
                'update_url' => route(
                    'accounts.integrations.notifications.update',
                    [$team, $destination],
                ),
                'destroy_url' => route(
                    'accounts.integrations.notifications.destroy',
                    [$team, $destination],
                ),
                'test_url' => route(
                    'accounts.integrations.notifications.test',
                    [$team, $destination],
                ),
            ])
            ->values();
        $requestedDestinationId = trim((string) $request->query('selected', ''));
        $selectedNotificationDestinationId = $notificationDestinations->contains(
            fn (array $destination) => $destination['id'] === $requestedDestinationId,
        ) ? $requestedDestinationId : null;

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
                'notifications' => route('accounts.integrations.notifications.index', $team),
                'sentry' => route('accounts.integrations.sentry.index', $team),
            ],
            'notificationsEnabled' => (bool) config('integrations.enabled')
                && (bool) config('integrations.notifications.enabled'),
            'storeNotificationUrl' => route('accounts.integrations.notifications.store', $team),
            'notificationProviders' => collect([
                IntegrationProvider::Slack,
                IntegrationProvider::Discord,
                IntegrationProvider::Telegram,
            ])->map(fn (IntegrationProvider $provider) => [
                'value' => $provider->value,
                'name' => $provider->label(),
                'mark' => match ($provider) {
                    IntegrationProvider::Slack => 'SL',
                    IntegrationProvider::Discord => 'DC',
                    IntegrationProvider::Telegram => 'TG',
                    default => '',
                },
                'description' => match ($provider) {
                    IntegrationProvider::Slack => 'Route messages through an incoming webhook.',
                    IntegrationProvider::Discord => 'Post messages to a Discord channel webhook.',
                    IntegrationProvider::Telegram => 'Send messages from a bot to a chat or channel.',
                    default => '',
                },
            ])->values(),
            'notificationEvents' => NotificationEventCatalog::all(),
            'notificationGlobalVariables' => NotificationEventCatalog::globalVariables(),
            'notificationDestinations' => $notificationDestinations,
            'selectedNotificationDestinationId' => $selectedNotificationDestinationId,
            'sentryEnabled' => (bool) config('integrations.enabled')
                && (bool) config('integrations.sentry.enabled'),
            'storeSentryUrl' => route('accounts.integrations.sentry.store', $team),
            'sentryDsns' => $team->sentryDsns()
                ->with(['integration', 'routes.mobileApp', 'routes.appIdentifier'])
                ->withCount('events')
                ->latest()
                ->get()
                ->map(fn ($dsn) => [
                    'id' => $dsn->id,
                    'name' => $dsn->integration->name,
                    'status' => $dsn->integration->status->value,
                    'dsn' => $dsn->dsn(),
                    'routing_method' => $dsn->routing_method->value,
                    'event_source' => $dsn->event_source?->value,
                    'app_key' => $dsn->app_key,
                    'identifier_key' => $dsn->identifier_key,
                    'upstream_configured' => filled($dsn->upstream_dsn),
                    'events_count' => $dsn->events_count,
                    'consecutive_forward_failures' => $dsn->consecutive_forward_failures,
                    'last_event_at' => $dsn->last_event_at,
                    'last_forwarded_at' => $dsn->last_forwarded_at,
                    'routes' => $dsn->routes->map(fn ($route) => [
                        'mobile_app_id' => $route->mobile_app_id,
                        'app_identifier_id' => $route->app_identifier_id,
                        'app_name' => $route->mobileApp->name,
                        'app_slug' => $route->mobileApp->slug,
                        'identifier' => $route->appIdentifier?->identifier,
                        'identifier_name' => $route->appIdentifier?->name,
                        'platform' => $route->appIdentifier?->platform?->value,
                        'dsn' => $route->public_key ? $dsn->dsn($route->public_key) : $dsn->dsn(),
                    ])->values(),
                    'update_url' => route('accounts.integrations.sentry.update', [$team, $dsn]),
                    'destroy_url' => route('accounts.integrations.sentry.destroy', [$team, $dsn]),
                ])->values(),
            'sentryEvents' => $team->sentryEvents()
                ->with(['mobileApp', 'appIdentifier', 'sentryDsn.integration', 'envelope'])
                ->latest('occurred_at')
                ->limit(50)
                ->get()
                ->map(fn ($event) => [
                    'id' => $event->id,
                    'event_id' => $event->event_id,
                    'dsn_name' => $event->sentryDsn->integration->name,
                    'app_name' => $event->mobileApp->name,
                    'app_slug' => $event->mobileApp->slug,
                    'identifier' => $event->appIdentifier?->identifier,
                    'platform' => $event->appIdentifier?->platform?->value,
                    'item_type' => $event->item_type,
                    'level' => $event->level,
                    'title' => $event->title,
                    'transaction' => $event->transaction,
                    'trace_id' => $event->trace_id,
                    'release' => $event->release,
                    'environment' => $event->environment,
                    'occurred_at' => $event->occurred_at,
                    'forward_status' => $event->envelope->status->value,
                ])->values(),
            'storeWebhookUrl' => route('accounts.integrations.webhooks.store', $team),
            'webhooksEnabled' => (bool) config('integrations.enabled')
                && (bool) config('integrations.webhooks.enabled'),
            'webhookEventTypes' => WebhookEventType::subscribableValues(),
            'newWebhookSecret' => $request->session()->pull('webhook_secret'),
            'profileTypes' => array_map(fn (ProfileType $type) => $type->value, ProfileType::cases()),
            'buildStatuses' => array_map(fn (BuildStatus $status) => $status->value, BuildStatus::cases()),
            'reportingModes' => array_map(fn (BuildReportingMode $mode) => $mode->value, BuildReportingMode::cases()),
            'apps' => $this->presentApps($team),
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
                ->whereIn('status', [
                    IntegrationStatus::Active->value,
                    IntegrationStatus::Degraded->value,
                ])
                ->with(['expoProjects' => fn ($query) => $query
                    ->whereNotNull('mobile_app_id')
                    ->where('exists_on_expo', true)])
                ->addSelect([
                    'linked_apps_count' => CiCdPipeline::query()
                        ->selectRaw('count(distinct mobile_app_id)')
                        ->whereColumn('integration_id', 'integrations.id'),
                ])
                ->withCount('pipelines')
                ->latest()
                ->get()
                ->map(fn (Integration $integration) => $this->presentCiCdIntegration($team, $integration)),
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

    private function presentApps(Team $team)
    {
        return $team->mobileApps()
            ->with(['appIdentifiers', 'expoProject'])
            ->orderBy('name')
            ->get()
            ->map(fn ($app) => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'tags' => collect($app->tagList())->pluck(0)->values(),
                'expo_project_id' => $app->expoProject?->external_id,
                'identifiers' => $app->appIdentifiers->map(fn ($identifier) => [
                    'id' => $identifier->id,
                    'name' => $identifier->name ?: $identifier->identifier,
                    'identifier' => $identifier->identifier,
                    'platform' => $identifier->platform->value,
                ]),
            ]);
    }

    private function presentCiCdIntegration(
        Team $team,
        Integration $integration,
        bool $withManagement = false,
    ): array {
        $data = [
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
            'linked_apps_count' => (int) ($integration->getAttribute('linked_apps_count')
                ?? ($integration->relationLoaded('pipelines')
                    ? $integration->pipelines->pluck('mobile_app_id')->unique()->count()
                    : 0)),
            'has_eligible_apps' => $integration->provider === IntegrationProvider::Expo
                ? $integration->expoProjects->isNotEmpty()
                : $team->mobileApps()->exists(),
            'last_synced_at' => $integration->last_synced_at,
            'detail_url' => route('accounts.integrations.cicd.show', [$team, $integration]),
        ];

        if (! $withManagement) {
            return [
                ...$data,
                'eligible_app_ids' => $integration->provider === IntegrationProvider::Expo
                    ? $integration->expoProjects->pluck('mobile_app_id')->values()
                    : $team->mobileApps()->pluck('id'),
            ];
        }

        return [
            ...$data,
            'index_url' => route('accounts.integrations.index', $team),
            'configuration_url' => $integration->provider === IntegrationProvider::Expo
                ? route('accounts.integrations.eas.index', $team)
                : null,
            'repositories_url' => route('accounts.integrations.repositories.index', [$team, $integration]),
            'workflows_url' => route('accounts.integrations.workflows.index', [$team, $integration]),
            'refs_url' => route('accounts.integrations.refs.index', [$team, $integration]),
            'pipelines_store_url' => route('accounts.integrations.pipelines.store', [$team, $integration]),
            'pipelines_batch_rename_url' => route('accounts.integrations.pipelines.batch.rename', [$team, $integration]),
            'pipelines_batch_destroy_url' => route('accounts.integrations.pipelines.batch.destroy', [$team, $integration]),
            'pipelines_batch_run_url' => route('accounts.integrations.pipelines.batch.runs.store', [$team, $integration]),
            'sync_url' => route("accounts.integrations.{$integration->provider->value}.sync", [$team, $integration]),
            'disconnect_url' => route("accounts.integrations.{$integration->provider->value}.disconnect", [$team, $integration]),
        ];
    }

    private function presentCiCdPipeline(
        Team $team,
        Integration $integration,
        CiCdPipeline $pipeline,
    ): array {
        return [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'enabled' => $pipeline->enabled,
            'mobile_app_id' => $pipeline->mobile_app_id,
            'app_identifier_id' => $pipeline->app_identifier_id,
            'app_name' => $pipeline->mobileApp->name,
            'target_name' => $pipeline->appIdentifier?->name ?: $pipeline->appIdentifier?->identifier,
            'platform' => $pipeline->appIdentifier?->platform?->value,
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
        ];
    }

    private function ciCdPipelineFilters(Request $request): array
    {
        $platform = trim((string) $request->query('platform', ''));
        $status = trim((string) $request->query('status', ''));
        $profile = trim((string) $request->query('profile', ''));

        return [
            'search' => mb_substr(trim((string) $request->query('search', '')), 0, 100),
            'platform' => in_array($platform, array_column(Platform::cases(), 'value'), true)
                ? $platform
                : '',
            'status' => in_array($status, ['enabled', 'disabled'], true) ? $status : '',
            'profile' => in_array($profile, array_column(ProfileType::cases(), 'value'), true)
                ? $profile
                : '',
        ];
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
