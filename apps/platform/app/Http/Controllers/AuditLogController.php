<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\AppIdentifier;
use App\Models\Audit;
use App\Models\BuildRecord;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use App\Support\Audit\AuditEntryPresenter;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request, Team $team): Response
    {
        $role = $request->user() ? $team->roleFor($request->user()) : null;
        abort_unless(in_array($role, [TeamRole::Owner, TeamRole::Admin], true), 403);

        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $filters = [
            'apps' => $this->filterValues($request->input('apps', $request->input('app'))),
            'actors' => $this->filterValues($request->input('actors', $request->input('user'))),
            'events' => $this->filterValues($request->input('events', $request->input('event'))),
            'sources' => $this->filterValues($request->input('sources')),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
        ];

        $audits = Audit::query()
            ->where('team_id', $team->id)
            ->when($filters['apps'], function ($query, array $appIds) use ($team) {
                $apps = $team->mobileApps()->whereIn('id', $appIds)->get();

                $query->where(function ($query) use ($apps) {
                    if ($apps->isEmpty()) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    foreach ($apps as $app) {
                        $query->orWhere(function ($query) use ($app) {
                            $query
                                ->where(fn ($query) => $query
                                    ->where('auditable_type', MobileApp::class)
                                    ->where('auditable_id', $app->id))
                                // audits.auditable_id is VARCHAR, while related model IDs are native UUIDs in PostgreSQL.
                                ->orWhere(fn ($query) => $query
                                    ->where('auditable_type', AppIdentifier::class)
                                    ->whereIn('auditable_id', $app->appIdentifiers()->selectRaw('CAST(app_identifiers.id AS VARCHAR)')))
                                ->orWhere(fn ($query) => $query
                                    ->where('auditable_type', BuildRecord::class)
                                    ->whereIn('auditable_id', $app->buildRecords()->selectRaw('CAST(build_records.id AS VARCHAR)')));
                        });
                    }
                });
            })
            ->when($filters['events'], fn ($query, array $events) => $query->whereIn('event', $events))
            ->when($filters['actors'], fn ($query, array $actors) => $query->whereIn('user_id', $actors))
            ->when($filters['sources'], fn ($query, array $sources) => $query->whereIn('source', $sources))
            ->when($filters['from'], fn ($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn ($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->with([
                'user:id,name',
                'apiKey:id,team_id,name',
                'auditable' => function (MorphTo $morphTo) {
                    $morphTo->morphWith([
                        AppIdentifier::class => ['mobileApp:id,team_id,name,slug'],
                        BuildRecord::class => [
                            'mobileApp:id,team_id,name,slug',
                            'appIdentifier:id,mobile_app_id,name,identifier,platform',
                        ],
                        Credential::class => [
                            'appIdentifiers:id,mobile_app_id,name,identifier,platform',
                            'appIdentifiers.mobileApp:id,team_id,name,slug',
                            'profiles:id,app_identifier_id',
                            'profiles.appIdentifier:id,mobile_app_id,name,identifier,platform',
                            'profiles.appIdentifier.mobileApp:id,team_id,name,slug',
                        ],
                        WebhookEndpoint::class => [
                            'integration:id,team_id,name,provider,kind',
                        ],
                    ]);
                },
            ])
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Audit $audit) => AuditEntryPresenter::present(
                $audit,
                $team,
                $role === TeamRole::Owner,
            ));

        return Inertia::render('AuditLog/Index', [
            'audits' => $audits,
            'filters' => $filters,
            'filterOptions' => [
                'apps' => $team->mobileApps()->orderBy('name')->get(['id', 'name']),
                'users' => $team->users()->orderBy('name')->get(['users.id', 'users.name']),
                'events' => Audit::query()
                    ->where('team_id', $team->id)
                    ->whereNotNull('event')
                    ->distinct()
                    ->orderBy('event')
                    ->pluck('event'),
                'sources' => Audit::query()
                    ->where('team_id', $team->id)
                    ->whereNotNull('source')
                    ->distinct()
                    ->orderBy('source')
                    ->pluck('source'),
            ],
        ]);
    }

    /** @return list<string> */
    private function filterValues(mixed $values): array
    {
        return collect(is_array($values) ? $values : [$values])
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();
    }
}
