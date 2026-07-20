<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
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
}
