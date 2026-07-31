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

        $filters = [
            'app' => $request->string('app')->toString(),
            'user' => $request->string('user')->toString(),
            'event' => $request->string('event')->toString(),
        ];

        $audits = Audit::query()
            ->where('team_id', $team->id)
            ->when($filters['app'], function ($query, string $appId) use ($team) {
                $app = $team->mobileApps()->find($appId);

                $query->where(function ($query) use ($app) {
                    if (! $app) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query
                        ->where(fn ($query) => $query
                            ->where('auditable_type', MobileApp::class)
                            ->where('auditable_id', $app->id))
                        // audits.auditable_id is VARCHAR, while related model IDs are native UUIDs in PostgreSQL.
                        ->orWhere(fn ($query) => $query
                            ->where('auditable_type', AppIdentifier::class)
                            ->whereIn(
                                'auditable_id',
                                $app->appIdentifiers()->selectRaw('CAST(app_identifiers.id AS VARCHAR)')
                            ))
                        ->orWhere(fn ($query) => $query
                            ->where('auditable_type', BuildRecord::class)
                            ->whereIn(
                                'auditable_id',
                                $app->buildRecords()->selectRaw('CAST(build_records.id AS VARCHAR)')
                            ));
                });
            })
            ->when($filters['event'], fn ($query, $event) => $query->where('event', $event))
            ->when($filters['user'], fn ($query, $user) => $query->where('user_id', $user))
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
            ],
        ]);
    }
}
