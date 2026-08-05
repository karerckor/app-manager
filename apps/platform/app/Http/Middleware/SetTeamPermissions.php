<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetTeamPermissions
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeTeam = $request->route('team');
        $team = $routeTeam instanceof Team ? $routeTeam : $request->user()?->currentTeam;

        app(PermissionRegistrar::class)->setPermissionsTeamId($team?->id);
        $request->user()?->unsetRelation('roles')->unsetRelation('permissions');

        return $next($request);
    }
}
