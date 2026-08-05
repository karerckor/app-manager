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
        $role = $user && $navigationTeam ? $user->teamRole($navigationTeam) : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user?->only('id', 'name', 'email', 'current_team_id'),
                'team' => $navigationTeam && $role ? [
                    'role' => $role->value,
                    'permissions' => [
                        'view' => $user->can('view', $navigationTeam),
                        'manage_credentials' => $user->can('manageCredentials', $navigationTeam),
                        'manage_applications' => $user->can('manageApplications', $navigationTeam),
                        'manage_team' => $user->can('manageTeam', $navigationTeam),
                        'close_team' => $user->can('delete', $navigationTeam),
                    ],
                ] : null,
            ],
            'navigationTeams' => fn () => $user?->teams()
                ->select('teams.id', 'teams.name', 'teams.slug', 'teams.owner_id')
                ->get() ?? [],
            'navigationTeam' => fn () => $navigationTeam ? [
                ...$navigationTeam->only('id', 'name', 'slug'),
                'close_url' => route('accounts.destroy', ['team' => $navigationTeam]),
            ] : null,
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'token' => fn () => $request->session()->get('token'),
            ],
        ];
    }
}
