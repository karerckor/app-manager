<?php

namespace App\Support\Audit\Resolvers;

use App\Support\Audit\ActorContextManager;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Contracts\Resolver;

class ApiKeyIdResolver implements Resolver
{
    public static function resolve(?Auditable $auditable = null): ?string
    {
        return app(ActorContextManager::class)->current()->apiKey?->id;
    }
}
