<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Http\Requests\AppTelemetryFilterRequest;
use App\Models\MobileApp;
use App\Models\SentryEvent;
use App\Models\SentryIssue;
use App\Models\Team;
use App\Support\Apps\AppContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Inertia\Inertia;
use Inertia\Response;

class AppTelemetryPageController extends Controller
{
    private const LIMIT = 100;

    public function __invoke(AppTelemetryFilterRequest $request, Team $team, MobileApp $mobileApp): Response
    {
        abort_unless(config('integrations.enabled') && config('integrations.sentry.enabled'), 404);

        $filters = $request->telemetryFilters();

        $baseQuery = $mobileApp->sentryEvents();
        $environments = (clone $baseQuery)->whereNotNull('environment')->where('environment', '!=', '')->distinct()->orderBy('environment')->pluck('environment')->values();
        $stats = [
            'issues' => $mobileApp->sentryIssues()->count(),
            'errors' => (clone $baseQuery)->where('item_type', 'event')->count(),
            'traces' => (clone $baseQuery)->where('item_type', 'transaction')->whereNotNull('trace_id')->distinct('trace_id')->count('trace_id'),
            'environments' => $environments->count(),
            'last_seen_at' => (clone $baseQuery)->latest('occurred_at')->first(['occurred_at'])?->occurred_at,
        ];

        $issueQuery = $mobileApp->sentryIssues()->with('appIdentifier');
        $this->applyIssueFilters($issueQuery, $filters);
        $matchingIssueCount = (clone $issueQuery)->count();
        $issues = $issueQuery->latest('last_seen_at')->latest('created_at')->limit(self::LIMIT)->get()->map(fn (SentryIssue $issue) => [
            'id' => $issue->id, 'fingerprint' => $issue->fingerprint, 'status' => $issue->status, 'level' => $issue->level,
            'title' => $issue->title, 'events_count' => $issue->events_count, 'first_seen_at' => $issue->first_seen_at,
            'last_seen_at' => $issue->last_seen_at, 'identifier' => $issue->appIdentifier?->identifier,
            'identifier_name' => $issue->appIdentifier?->name, 'identifier_platform' => $issue->appIdentifier?->platform?->value,
            'url' => route('accounts.apps.telemetry.issues.show', [$team, $mobileApp, $issue]),
        ])->values();

        $traceQuery = $mobileApp->sentryEvents()->with('appIdentifier');
        $this->applyEventFilters($traceQuery, $filters, 'transaction');
        $traces = $traceQuery->latest('occurred_at')->latest('created_at')->limit(self::LIMIT * 3)->get()
            ->filter(fn (SentryEvent $event) => filled($event->trace_id))->unique('trace_id')->take(self::LIMIT)->map(fn (SentryEvent $event) => [
                'id' => $event->id, 'title' => $event->title ?: $event->transaction ?: 'Untitled trace', 'trace_id' => $event->trace_id,
                'environment' => $event->environment, 'release' => $event->release, 'occurred_at' => $event->occurred_at,
                'identifier' => $event->appIdentifier?->identifier, 'identifier_platform' => $event->appIdentifier?->platform?->value,
                'url' => route('accounts.apps.telemetry.traces.show', [$team, $mobileApp, $event->trace_id]),
            ])->values();

        return Inertia::render('Apps/Telemetry/Index', [...AppContext::for($request, $team, $mobileApp), 'active' => 'telemetry', 'telemetry' => [
            'issues' => $issues, 'traces' => $traces, 'stats' => $stats, 'filters' => $filters, 'environments' => $environments,
            'matching_count' => $filters['view'] === 'traces' ? $traces->count() : $matchingIssueCount, 'limit' => self::LIMIT,
            'settings_url' => $team->allows($request->user(), TeamPermission::ManageIntegrations) ? route('accounts.integrations.sentry.index', $team) : null,
        ]]);
    }

    /** @param array{view: string, environment: string, identifiers: list<string>, search: string} $filters */
    private function applyEventFilters(HasMany $query, array $filters, ?string $itemType = null): void
    {
        if ($itemType !== null) {
            $query->where('item_type', $itemType);
        }
        if ($filters['environment'] !== '') {
            $query->where('environment', $filters['environment']);
        }
        if ($filters['identifiers'] !== []) {
            $query->whereIn('app_identifier_id', $filters['identifiers']);
        }
        if ($filters['search'] !== '') {
            $search = "%{$filters['search']}%";
            $query->where(fn (Builder $query) => $query->whereLike('title', $search)->orWhereLike('transaction', $search)->orWhereLike('event_id', $search)->orWhereLike('trace_id', $search)->orWhereLike('release', $search));
        }
    }

    /** @param array{view: string, environment: string, identifiers: list<string>, search: string} $filters */
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
}
