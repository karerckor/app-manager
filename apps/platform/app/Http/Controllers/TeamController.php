<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Support\Credentials\StorageModePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Team/Create', [
            'storeUrl' => route('accounts.store'),
        ]);
    }

    public function index(Request $request, Team $team): Response
    {
        abort_unless($team->allows($request->user(), TeamPermission::View), 403);

        $canManage = $team->allows($request->user(), TeamPermission::ManageTeam);
        $members = $team->users()
            ->orderByRaw('CASE WHEN users.id = ? THEN 0 ELSE 1 END', [$team->owner_id])
            ->orderBy('users.name')
            ->get()
            ->map(fn ($user) => [
                ...$user->only('id', 'name', 'email'),
                'role' => $user->pivot->role,
                'joined_at' => $user->pivot->created_at,
                'is_owner' => $user->id === $team->owner_id,
                'is_current_user' => $user->id === $request->user()->id,
                'update_url' => route('accounts.team.members.update', ['team' => $team, 'user' => $user]),
                'remove_url' => route('accounts.team.members.destroy', ['team' => $team, 'user' => $user]),
            ]);

        $invitations = $canManage
            ? $team->invitations()
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->latest()
                ->get()
                ->map(fn ($invitation) => [
                    ...$invitation->only('id', 'email', 'role', 'created_at', 'expires_at'),
                    'revoke_url' => route('accounts.team.invitations.destroy', ['team' => $team, 'invitation' => $invitation]),
                ])
            : [];

        return Inertia::render('Team/Index', [
            'members' => $members,
            'invitations' => $invitations,
            'roles' => collect([TeamRole::Admin, TeamRole::Contributor, TeamRole::Viewer])
                ->map(fn (TeamRole $role) => ['value' => $role->value, 'label' => ucfirst($role->value)])
                ->all(),
            'canManage' => $canManage,
            'checkCredentialUniquenessByDefault' => $team->check_credential_uniqueness_by_default,
            'storageModes' => [
                'instance' => array_map(
                    fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()],
                    app(StorageModePolicy::class)->instanceModes(),
                ),
                'selected' => $team->allowed_credential_storage_modes,
            ],
            'updateTeamUrl' => route('accounts.team.update', ['team' => $team]),
            'inviteUrl' => route('accounts.team.invitations.store', ['team' => $team]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $user = $request->user();
        $team = $user->teams()->create([
            'name' => $data['name'],
            'owner_id' => $user->id,
        ], ['role' => 'owner']);

        $team->recordMembershipAudit($user, 'membership_added', 'owner');

        $user->update(['current_team_id' => $team->id]);

        return redirect()->route('accounts.apps.index', ['team' => $team])->with('status', 'Team created.');
    }

    public function switch(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->teams()->whereKey($team->id)->exists(), 403);
        $request->user()->update(['current_team_id' => $team->id]);

        return redirect()->route('accounts.apps.index', ['team' => $team])->with('status', 'Current team changed.');
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        abort_unless($team->allows($request->user(), TeamPermission::ManageTeam), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'check_credential_uniqueness_by_default' => ['sometimes', 'required', 'boolean'],
            'allowed_credential_storage_modes' => ['sometimes', 'nullable', 'array'],
            'allowed_credential_storage_modes.*' => [
                Rule::in(app(StorageModePolicy::class)->allowedValuesFor($team)),
            ],
        ]);
        $team->update($data);

        return back()->with(
            'status',
            array_key_exists('check_credential_uniqueness_by_default', $data)
                ? 'Credential uniqueness default updated.'
                : (array_key_exists('allowed_credential_storage_modes', $data)
                    ? 'Permitted credential storage updated.'
                    : 'Team name updated.'),
        );
    }
}
