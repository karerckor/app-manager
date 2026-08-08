<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeamMemberController extends Controller
{
    use PasswordValidationRules;

    public function store(Request $request, Team $team): RedirectResponse
    {
        abort_if(config('team.invitations_enabled', true), 404);

        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique(User::class)],
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required', 'string'],
            'role' => ['required', Rule::enum(TeamRole::class), Rule::notIn([TeamRole::Owner->value])],
        ]);

        $user = DB::transaction(function () use ($data, $team): User {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => $data['email'],
                'password' => $data['password'],
                'current_team_id' => $team->id,
            ]);

            $team->addUser($user, $data['role']);
            $team->recordMembershipAudit($user, 'membership_added', $data['role']);

            return $user;
        });

        return back()->with('status', "{$user->name} was added to the team.");
    }

    public function update(Request $request, Team $team, User $user): RedirectResponse
    {
        $this->authorizeMemberChange($request, $team, $user);

        $data = $request->validate([
            'role' => ['required', Rule::enum(TeamRole::class), Rule::notIn([TeamRole::Owner->value])],
        ]);

        abort_unless($team->users()->whereKey($user->id)->exists(), 404);
        $oldRole = $team->roleFor($user)?->value;
        $team->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
        $team->recordMembershipAudit($user, 'membership_role_changed', $data['role']);

        return back()->with('status', "{$user->name}'s role changed from {$oldRole} to {$data['role']}.");
    }

    public function destroy(Request $request, Team $team, User $user): RedirectResponse
    {
        $this->authorizeMemberChange($request, $team, $user);

        abort_unless($team->users()->whereKey($user->id)->exists(), 404);
        $oldRole = $team->roleFor($user)?->value;
        $team->users()->detach($user->id);
        $team->recordMembershipAudit($user, 'membership_removed', $oldRole);

        if ($user->current_team_id === $team->id) {
            $user->update(['current_team_id' => $user->teams()->value('teams.id')]);
        }

        return back()->with('status', "{$user->name} removed from the team.");
    }

    private function authorizeMemberChange(Request $request, Team $team, User $user): void
    {
        abort_if($user->id === $team->owner_id || $user->id === $request->user()->id, 422, 'The owner or your own membership cannot be changed here.');
    }
}
