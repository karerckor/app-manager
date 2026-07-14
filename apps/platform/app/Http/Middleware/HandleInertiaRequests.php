<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $routeTeam = $request->route('team');
        $navigationTeam = $routeTeam instanceof Team ? $routeTeam : $user?->currentTeam;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->only('id', 'name', 'email', 'current_team_id'),
            ],
            'navigationTeams' => fn () => $user?->teams()
                ->select('teams.id', 'teams.name', 'teams.slug', 'teams.owner_id')
                ->get() ?? [],
            'navigationTeam' => fn () => $navigationTeam?->only('id', 'name', 'slug'),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'token' => fn () => $request->session()->get('token'),
            ],
        ];
    }
}
