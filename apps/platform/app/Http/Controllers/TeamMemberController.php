<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamMemberController extends Controller
{
    public function update(Request $request, Team $team, User $user): RedirectResponse
    {
        $this->authorizeManagement($request, $team);
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
        $this->authorizeManagement($request, $team);
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

    private function authorizeManagement(Request $request, Team $team): void
    {
        abort_unless($team->allows($request->user(), TeamPermission::ManageTeam), 403);
    }

    private function authorizeMemberChange(Request $request, Team $team, User $user): void
    {
        abort_if($user->id === $team->owner_id || $user->id === $request->user()->id, 422, 'The owner or your own membership cannot be changed here.');
    }
}
