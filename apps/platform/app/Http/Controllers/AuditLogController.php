<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request, Team $team): Response
    {
        abort_unless($request->user()?->id === $team->owner_id, 403);

        $audits = Audit::query()
            ->where('team_id', $team->id)
            ->when($request->query('event'), fn ($q, $event) => $q->where('event', $event))
            ->when($request->query('user'), fn ($q, $user) => $q->where('user_id', $user))
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

        return Inertia::render('AuditLog/Index', ['audits' => $audits]);
    }
}
