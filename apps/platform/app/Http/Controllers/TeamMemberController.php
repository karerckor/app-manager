<?php

namespace App\Http\Controllers;

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
        $this->authorize('manageTeam', $team);
        $this->member($team, $user);
        abort_if($user->id === $team->owner_id, 422, 'The owner role cannot be changed.');

        $data = $request->validate([
            'role' => [
                'required',
                Rule::enum(TeamRole::class),
                Rule::notIn([TeamRole::Owner->value]),
            ],
        ]);

        $team->updateUserRole($user, TeamRole::from($data['role']));

        return back()->with('status', 'Member role updated.');
    }

    public function destroy(Request $request, Team $team, User $user): RedirectResponse
    {
        $this->authorize('manageTeam', $team);
        $this->member($team, $user);
        abort_if($user->id === $team->owner_id, 422, 'The owner cannot be removed.');

        $team->removeUser($user);

        if ($user->current_team_id === $team->id) {
            $nextTeam = $user->teams()->first();
            $user->update(['current_team_id' => $nextTeam?->id]);
        }

        if ($user->id === $request->user()->id) {
            return redirect()->route('dashboard')->with('status', 'You left the team.');
        }

        return back()->with('status', 'Member removed.');
    }

    private function member(Team $team, User $user): void
    {
        abort_unless($team->users()->whereKey($user->id)->exists(), 404);
    }
}
