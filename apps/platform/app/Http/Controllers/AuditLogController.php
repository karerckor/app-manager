<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\AppIdentifier;
use App\Models\Audit;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
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
                        ->orWhere(fn ($query) => $query
                            ->where('auditable_type', AppIdentifier::class)
                            ->whereIn('auditable_id', $app->appIdentifiers()->select('id')))
                        ->orWhere(fn ($query) => $query
                            ->where('auditable_type', BuildRecord::class)
                            ->whereIn('auditable_id', $app->buildRecords()->select('id')));
                });
            })
            ->when($filters['event'], fn ($query, $event) => $query->where('event', $event))
            ->when($filters['user'], fn ($query, $user) => $query->where('user_id', $user))
            ->with('user:id,name')
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Audit $audit) => [
                'id' => $audit->id,
                'event' => $audit->event,
                'auditable_type' => class_basename($audit->auditable_type),
                'auditable_id' => $audit->auditable_id,
                'source' => $audit->source,
                'actor' => $audit->user ? ['id' => $audit->user->id, 'name' => $audit->user->name] : null,
                'created_at' => $audit->created_at?->toISOString(),
            ]);

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
