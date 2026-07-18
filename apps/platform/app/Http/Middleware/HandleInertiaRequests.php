<?php

namespace App\Http\Middleware;

use App\Enums\TeamPermission;
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
        $teamRole = $user && $navigationTeam ? $navigationTeam->roleFor($user) : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->only('id', 'name', 'email', 'current_team_id'),
                'team' => $navigationTeam && $teamRole ? [
                    'id' => $navigationTeam->id,
                    'role' => $teamRole->value,
                    'permissions' => collect(TeamPermission::cases())->mapWithKeys(fn (TeamPermission $permission) => [
                        $permission->value => $teamRole->allows($permission),
                    ])->all(),
                ] : null,
            ],
            'navigationTeams' => fn () => $user?->teams()
                ->select('teams.id', 'teams.name', 'teams.slug', 'teams.owner_id')
                ->get() ?? [],
            'navigationTeam' => fn () => $navigationTeam?->only('id', 'name', 'slug', 'owner_id'),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'token' => fn () => $request->session()->get('token'),
            ],
        ];
    }
}
