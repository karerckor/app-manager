<?php

namespace App\Support\Audit\Resolvers;

use App\Support\Audit\ActorContextManager;
use OwenIt\Auditing\Contracts\Resolver;

class TeamIdResolver implements Resolver
{
    /**
     * The vendor `Resolver` contract types this parameter as the non-nullable
     * `OwenIt\Auditing\Contracts\Auditable`. It is widened to `mixed` here
     * (a valid contravariant override) because this resolver is also
     * exercised directly against plain Eloquent models (e.g. in tests) that
     * expose a `team_id` attribute without implementing the full Auditable
     * interface.
     */
    public static function resolve(mixed $auditable = null): ?string
    {
        $teamId = $auditable?->getAttribute('team_id');

        if ($teamId !== null) {
            return $teamId;
        }

        return app(ActorContextManager::class)->current()->originator?->current_team_id;
    }
}
