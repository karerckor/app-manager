<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('team');
        $user = $request->user();
        $apiKey = $user?->currentAccessToken();

        abort_unless(
            $team instanceof Team
            && $user?->teams()->whereKey($team->id)->exists()
            && (! $apiKey instanceof ApiKey || $apiKey->team_id === $team->id),
            403,
        );

        return $next($request);
    }
}
