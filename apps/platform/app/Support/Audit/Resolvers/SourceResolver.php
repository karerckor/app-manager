<?php

namespace App\Support\Audit\Resolvers;

use App\Support\Audit\ActorContextManager;
use OwenIt\Auditing\Contracts\Resolver;

class SourceResolver implements Resolver
{
    public static function resolve(mixed $auditable = null): string
    {
        return app(ActorContextManager::class)->current()->source;
    }
}
