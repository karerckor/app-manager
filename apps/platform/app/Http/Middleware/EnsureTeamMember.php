<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('team');

        abort_unless(
            $team instanceof Team
            && $request->user()?->teams()->whereKey($team->id)->exists(),
            403,
        );

        return $next($request);
    }
}
