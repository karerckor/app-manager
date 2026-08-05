<?php

namespace App\Http\Controllers;

use App\Http\Requests\AppTelemetryEventRequest;
use App\Models\MobileApp;
use App\Models\SentryEvent;
use App\Models\SentryIssue;
use App\Models\Team;
use App\Support\Apps\AppContext;
use App\Support\Sentry\SentryTelemetryPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AppTelemetryDetailPageController extends Controller
{
    private const TIMELINE_LIMIT = 100;

    public function issue(AppTelemetryEventRequest $request, Team $team, MobileApp $mobileApp, SentryIssue $sentryIssue, SentryTelemetryPresenter $presenter): Response
    {
        abort_unless(config('integrations.enabled') && config('integrations.sentry.enabled'), 404);
        abort_unless($sentryIssue->mobile_app_id === $mobileApp->id, 404);

        $events = $sentryIssue->events()->with(['appIdentifier', 'sentryDsn.integration', 'envelope'])->latest('occurred_at')->latest('created_at')->limit(self::TIMELINE_LIMIT)->get();
        $selected = $this->selectedEvent($request, $events);
        abort_unless($selected, 404);
        $traceCount = $selected->trace_id ? $mobileApp->sentryEvents()->where('trace_id', $selected->trace_id)->count() : 0;

        return $this->render($request, $team, $mobileApp, $presenter, [
            'kind' => 'issue', 'title' => $sentryIssue->title, 'level' => $sentryIssue->level,
            'status' => $sentryIssue->status, 'count' => $sentryIssue->events_count,
            'first_seen_at' => $sentryIssue->first_seen_at, 'last_seen_at' => $sentryIssue->last_seen_at,
            'identifier' => $sentryIssue->appIdentifier?->identifier, 'identifier_platform' => $sentryIssue->appIdentifier?->platform?->value,
            'fingerprint' => $sentryIssue->fingerprint,
            'timeline' => $events->map(fn (SentryEvent $event) => $presenter->occurrence($event, route('accounts.apps.telemetry.issues.show', [$team, $mobileApp, $sentryIssue, 'event' => $event->id])))->values(),
            'selected_event' => $presenter->event($selected, $traceCount),
            'trace_url' => $selected->trace_id ? route('accounts.apps.telemetry.traces.show', [$team, $mobileApp, $selected->trace_id, 'event' => $selected->id]) : null,
        ]);
    }

    public function trace(AppTelemetryEventRequest $request, Team $team, MobileApp $mobileApp, string $traceId, SentryTelemetryPresenter $presenter): Response
    {
        abort_unless(config('integrations.enabled') && config('integrations.sentry.enabled'), 404);
        $events = $mobileApp->sentryEvents()->with(['appIdentifier', 'sentryDsn.integration', 'envelope'])->where('trace_id', $traceId)->latest('occurred_at')->latest('created_at')->limit(self::TIMELINE_LIMIT)->get();
        $selected = $this->selectedEvent($request, $events);
        abort_unless($selected, 404);
        $traceEvent = $events->firstWhere('item_type', 'transaction') ?? $selected;

        return $this->render($request, $team, $mobileApp, $presenter, [
            'kind' => 'trace', 'title' => $traceEvent->title ?: $traceEvent->transaction ?: 'Untitled trace',
            'trace_id' => $traceId, 'count' => $events->count(), 'first_seen_at' => $events->min('occurred_at'),
            'last_seen_at' => $events->max('occurred_at'),
            'timeline' => $events->sortBy('occurred_at')->values()->map(fn (SentryEvent $event) => $presenter->occurrence($event, route('accounts.apps.telemetry.traces.show', [$team, $mobileApp, $traceId, 'event' => $event->id])))->values(),
            'selected_event' => $presenter->event($selected, $events->count()),
            'trace_url' => null,
        ]);
    }

    /** @param Collection<int, SentryEvent> $events */
    private function selectedEvent(AppTelemetryEventRequest $request, $events): ?SentryEvent
    {
        $eventId = $request->eventId();

        return $eventId === null ? $events->first() : $events->firstWhere('id', $eventId);
    }

    /** @param array<string, mixed> $telemetry */
    private function render(Request $request, Team $team, MobileApp $mobileApp, SentryTelemetryPresenter $presenter, array $telemetry): Response
    {
        return Inertia::render('Apps/Telemetry/Detail', [...AppContext::for($request, $team, $mobileApp), 'active' => 'telemetry', 'telemetry' => [
            ...$telemetry,
            'overview_url' => route('accounts.apps.telemetry.index', [$team, $mobileApp]),
        ]]);
    }
}
