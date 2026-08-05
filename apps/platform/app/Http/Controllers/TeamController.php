<?php

namespace App\Http\Controllers;

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $user = $request->user();
        $team = Team::create([
            'name' => $data['name'],
            'owner_id' => $user->id,
        ]);
        $team->addUser($user, TeamRole::Owner);

        $user->update(['current_team_id' => $team->id]);

        return redirect()->route('accounts.apps.index', ['team' => $team])->with('status', 'Team created.');
    }

    public function switch(Request $request, Team $team): RedirectResponse
    {
        $this->authorize('view', $team);
        $request->user()->update(['current_team_id' => $team->id]);

        return redirect()->route('accounts.apps.index', ['team' => $team])->with('status', 'Current team changed.');
    }

    public function destroy(Request $request, Team $team): RedirectResponse
    {
        $this->authorize('delete', $team);

        $nextTeam = $request->user()->teams()
            ->whereKeyNot($team->id)
            ->first();

        $team->delete();
        $request->user()->update(['current_team_id' => $nextTeam?->id]);

        if ($nextTeam) {
            return redirect()
                ->route('accounts.apps.index', ['team' => $nextTeam])
                ->with('status', 'Team closed.');
        }

        return redirect()->route('dashboard')->with('status', 'Team closed.');
    }
}
