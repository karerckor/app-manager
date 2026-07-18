<?php

namespace App\Http\Middleware;

use App\Enums\TeamPermission;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $team = $request->route('team');
        $user = $request->user();
        $required = TeamPermission::tryFrom($permission);

        abort_unless(
            $required !== null
            && $team instanceof Team
            && $user !== null
            && $team->allows($user, $required),
            403,
        );

        return $next($request);
    }
}
