<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppTelemetryEventRequest;
use App\Http\Requests\AppTelemetryFilterRequest;
use App\Http\Requests\UpdateSentryIssueStatusRequest;
use App\Http\Requests\UpdateSentryIssueTagsRequest;
use App\Integrations\Sentry\SentryIssueStatusManager;
use App\Models\MobileApp;
use App\Models\SentryEvent;
use App\Models\SentryIssue;
use App\Models\Team;
use App\Support\Audit\ActorContextManager;
use App\Support\Sentry\SentryTelemetryPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;

class AppTelemetryController extends Controller
{
    public function issues(AppTelemetryFilterRequest $request, Team $team, MobileApp $mobileApp): JsonResponse
    {
        $this->ensureEnabled();
        $filters = $request->telemetryFilters();
        $query = $mobileApp->sentryIssues()->with([
            'appIdentifier', 'resolvedByUser', 'resolvedByApiKey', 'statusChangedByUser', 'statusChangedByApiKey',
        ]);
        $this->applyIssueFilters($query, $filters);
        $paginator = $query->latest('last_seen_at')->paginate($filters['per_page']);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (SentryIssue $issue) => $this->issueSummary($issue))->values(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ]);
    }

    public function issue(AppTelemetryEventRequest $request, Team $team, MobileApp $mobileApp, SentryIssue $sentryIssue, SentryTelemetryPresenter $presenter): JsonResponse
    {
        $this->ensureEnabled();
        abort_unless($sentryIssue->mobile_app_id === $mobileApp->id, 404);
        $sentryIssue->load([
            'appIdentifier', 'resolvedByUser', 'resolvedByApiKey', 'statusChangedByUser', 'statusChangedByApiKey',
        ]);
        $events = $sentryIssue->events()->with(['appIdentifier', 'sentryDsn.integration', 'envelope'])->latest('occurred_at')->latest('created_at')->limit(100)->get();
        $selected = $request->eventId() ? $events->firstWhere('id', $request->eventId()) : $events->first();
        abort_unless($selected, 404);
        $traceCount = $selected->trace_id ? $mobileApp->sentryEvents()->where('trace_id', $selected->trace_id)->count() : 0;

        return response()->json(['data' => [
            ...$this->issueSummary($sentryIssue->loadMissing('appIdentifier')),
            'timeline' => $events->map(fn (SentryEvent $event) => $presenter->occurrence($event, ''))->map(fn (array $event) => array_diff_key($event, ['url' => true]))->values(),
            'selected_event' => $presenter->event($selected, $traceCount, $this->archiveUrl($team, $mobileApp, $selected)),
        ]]);
    }

    public function updateIssue(
        UpdateSentryIssueStatusRequest $request,
        Team $team,
        MobileApp $mobileApp,
        SentryIssue $sentryIssue,
        SentryIssueStatusManager $statuses,
        ActorContextManager $actors,
    ): JsonResponse {
        $this->ensureEnabled();
        abort_unless($sentryIssue->mobile_app_id === $mobileApp->id, 404);
        $issue = $statuses->setStatus(
            $sentryIssue,
            $request->validated('status'),
            $actors->current(),
            $request->validated('comment'),
        );

        return response()->json(['data' => $this->issueSummary($issue->loadMissing('appIdentifier'))]);
    }

    public function updateIssueTags(
        UpdateSentryIssueTagsRequest $request,
        Team $team,
        MobileApp $mobileApp,
        SentryIssue $sentryIssue,
    ): JsonResponse {
        $this->ensureEnabled();
        abort_unless($sentryIssue->mobile_app_id === $mobileApp->id, 404);
        $sentryIssue->syncTags($request->normalizedTags())->save();

        return response()->json(['data' => $this->issueSummary($sentryIssue->refresh()->load([
            'appIdentifier', 'resolvedByUser', 'resolvedByApiKey', 'statusChangedByUser', 'statusChangedByApiKey',
        ]))]);
    }

    public function traces(AppTelemetryFilterRequest $request, Team $team, MobileApp $mobileApp): JsonResponse
    {
        $this->ensureEnabled();
        $filters = $request->telemetryFilters();
        $query = $mobileApp->sentryEvents()->with('appIdentifier');
        $this->applyEventFilters($query, $filters, 'transaction');
        $events = $query->latest('occurred_at')->latest('created_at')->limit($filters['per_page'] * 3)->get()->filter(fn (SentryEvent $event) => filled($event->trace_id))->unique('trace_id')->take($filters['per_page'])->values();

        return response()->json(['data' => $events->map(fn (SentryEvent $event) => [
            'trace_id' => $event->trace_id, 'title' => $event->title ?: $event->transaction ?: 'Untitled trace',
            'environment' => $event->environment, 'release' => $event->release, 'occurred_at' => $event->occurred_at,
            'identifier' => $event->appIdentifier?->identifier, 'identifier_platform' => $event->appIdentifier?->platform?->value,
        ]), 'meta' => ['per_page' => $filters['per_page'], 'count' => $events->count()]]);
    }

    public function trace(AppTelemetryEventRequest $request, Team $team, MobileApp $mobileApp, string $traceId, SentryTelemetryPresenter $presenter): JsonResponse
    {
        $this->ensureEnabled();
        $events = $mobileApp->sentryEvents()->with(['appIdentifier', 'sentryDsn.integration', 'envelope'])->where('trace_id', $traceId)->latest('occurred_at')->latest('created_at')->limit(100)->get();
        $selected = $request->eventId() ? $events->firstWhere('id', $request->eventId()) : $events->firstWhere('item_type', 'transaction') ?? $events->first();
        abort_unless($selected, 404);
        $traceEvent = $events->firstWhere('item_type', 'transaction') ?? $selected;

        return response()->json(['data' => [
            'trace_id' => $traceId, 'title' => $traceEvent->title ?: $traceEvent->transaction ?: 'Untitled trace', 'events_count' => $events->count(),
            'first_seen_at' => $events->min('occurred_at'), 'last_seen_at' => $events->max('occurred_at'),
            'timeline' => $events->sortBy('occurred_at')->values()->map(fn (SentryEvent $event) => $presenter->occurrence($event, ''))->map(fn (array $event) => array_diff_key($event, ['url' => true]))->values(),
            'selected_event' => $presenter->event($selected, $events->count(), $this->archiveUrl($team, $mobileApp, $selected)),
        ]]);
    }

    /** @return array<string, mixed> */
    private function issueSummary(SentryIssue $issue): array
    {
        return [
            'id' => $issue->id, 'fingerprint' => $issue->fingerprint, 'status' => $issue->status, 'level' => $issue->level,
            'title' => $issue->title, 'events_count' => $issue->events_count, 'first_seen_at' => $issue->first_seen_at,
            'last_seen_at' => $issue->last_seen_at, 'identifier' => $issue->appIdentifier?->identifier,
            'identifier_platform' => $issue->appIdentifier?->platform?->value, 'resolved_at' => $issue->resolved_at,
            'tags' => $issue->tagList(),
            'resolved_by' => $issue->status === 'resolved' ? [
                'type' => $issue->resolvedByApiKey ? 'api_key' : 'user',
                'id' => $issue->resolvedByApiKey?->id ?? $issue->resolvedByUser?->id,
                'name' => $issue->resolvedByApiKey?->name ?? $issue->resolvedByUser?->name ?? 'Unknown user',
                'user' => $issue->resolvedByApiKey && $issue->resolvedByUser ? ['id' => $issue->resolvedByUser->id, 'name' => $issue->resolvedByUser->name] : null,
            ] : null,
            'status_activity' => $this->statusActivity($issue),
        ];
    }

    /** @return array<string, mixed>|null */
    private function statusActivity(SentryIssue $issue): ?array
    {
        if ($issue->status_changed_at === null) {
            return null;
        }

        return [
            'action' => $issue->status === 'resolved' ? 'resolved' : 'reopened',
            'comment' => $issue->status_comment,
            'changed_at' => $issue->status_changed_at,
            'actor' => $issue->statusChangedByApiKey ? [
                'type' => 'api_key',
                'id' => $issue->statusChangedByApiKey->id,
                'name' => $issue->statusChangedByApiKey->name,
                'user' => $issue->statusChangedByUser ? [
                    'id' => $issue->statusChangedByUser->id, 'name' => $issue->statusChangedByUser->name,
                ] : null,
            ] : ($issue->statusChangedByUser ? [
                'type' => 'user', 'id' => $issue->statusChangedByUser->id,
                'name' => $issue->statusChangedByUser->name, 'user' => null,
            ] : [
                'type' => 'system', 'id' => null, 'name' => 'System', 'user' => null,
            ]),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applyIssueFilters(HasMany $query, array $filters): void
    {
        if ($filters['environment'] !== '') {
            $query->whereHas('events', fn (Builder $events) => $events->where('environment', $filters['environment']));
        }
        if ($filters['identifiers'] !== []) {
            $query->whereIn('app_identifier_id', $filters['identifiers']);
        }
        if ($filters['search'] !== '') {
            $search = "%{$filters['search']}%";
            $query->where(fn (Builder $issues) => $issues->whereLike('title', $search)->orWhereLike('fingerprint', $search)->orWhereHas('events', fn (Builder $events) => $events->whereLike('event_id', $search)->orWhereLike('trace_id', $search)->orWhereLike('release', $search)));
        }
    }

    /** @param array<string, mixed> $filters */
    private function applyEventFilters(HasMany $query, array $filters, string $type): void
    {
        $query->where('item_type', $type);
        if ($filters['environment'] !== '') {
            $query->where('environment', $filters['environment']);
        }
        if ($filters['identifiers'] !== []) {
            $query->whereIn('app_identifier_id', $filters['identifiers']);
        }
        if ($filters['search'] !== '') {
            $search = "%{$filters['search']}%";
            $query->where(fn (Builder $events) => $events->whereLike('title', $search)->orWhereLike('transaction', $search)->orWhereLike('event_id', $search)->orWhereLike('trace_id', $search)->orWhereLike('release', $search));
        }
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('integrations.enabled') && config('integrations.sentry.enabled'), 404);
    }

    private function archiveUrl(Team $team, MobileApp $mobileApp, SentryEvent $event): ?string
    {
        return $event->envelope?->archive_path
            ? route('api.v1.apps.telemetry.envelopes.archive', [$team, $mobileApp, $event->envelope])
            : null;
    }
}
