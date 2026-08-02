<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnsModel
{
    public function handle(Request $request, Closure $next, string $model, string $ownerColumn = 'team_id', string $ownerParam = 'team'): Response
    {
        $modelInstance = $request->route($model);
        $ownerInstance = $request->route($ownerParam);

        if ($modelInstance === null || $ownerInstance === null) {
            abort(404);
        }

        abort_unless(
            $modelInstance->{$ownerColumn} === $ownerInstance->id,
            404,
        );

        return $next($request);
    }
}
