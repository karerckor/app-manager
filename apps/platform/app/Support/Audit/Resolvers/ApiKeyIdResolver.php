<?php

namespace App\Support\Audit\Resolvers;

use App\Support\Audit\ActorContextManager;
use OwenIt\Auditing\Contracts\Resolver;

class ApiKeyIdResolver implements Resolver
{
    public static function resolve(mixed $auditable = null): ?int
    {
        return app(ActorContextManager::class)->current()->apiKey?->id;
    }
}
