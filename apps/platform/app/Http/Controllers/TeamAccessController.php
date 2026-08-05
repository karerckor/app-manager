<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamAccessController extends Controller
{
    public function __invoke(Request $request, Team $team): Response
    {
        $this->authorize('view', $team);

        $canManageTeam = $request->user()->can('manageTeam', $team);
        $members = $team->users()
            ->with('roles')
            ->orderByRaw('CASE WHEN users.id = ? THEN 0 ELSE 1 END', [$team->owner_id])
            ->orderBy('users.name')
            ->get();

        return Inertia::render('Teams/Access', [
            'team' => [
                ...$team->only('id', 'name', 'slug'),
                'owner_id' => $team->owner_id,
            ],
            'members' => $members->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => TeamRole::tryFrom((string) $member->roles->first()?->name)?->value,
                'is_owner' => $member->id === $team->owner_id,
                'is_current_user' => $member->id === $request->user()->id,
                'update_url' => route('accounts.members.update', [$team, $member]),
                'destroy_url' => route('accounts.members.destroy', [$team, $member]),
            ]),
            'invitations' => $canManageTeam
                ? $team->invitations()->pending()->latest()->get()->map(fn ($invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'expires_at' => $invitation->expires_at,
                    'destroy_url' => route('accounts.invitations.destroy', [$team, $invitation]),
                ])
                : [],
            'roles' => collect(TeamRole::cases())
                ->filter->canBeAssigned()
                ->map(fn (TeamRole $role) => [
                    'value' => $role->value,
                    'label' => $role->label(),
                    'description' => $role->description(),
                ])
                ->values(),
            'inviteUrl' => route('accounts.invitations.store', $team),
        ]);
    }
}
