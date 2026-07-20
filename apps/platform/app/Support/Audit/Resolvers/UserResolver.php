<?php

namespace App\Support\Audit\Resolvers;

use App\Support\Audit\ActorContextManager;
use Illuminate\Contracts\Auth\Authenticatable;
use OwenIt\Auditing\Contracts\UserResolver as UserResolverContract;

class UserResolver implements UserResolverContract
{
    public static function resolve(): ?Authenticatable
    {
        return app(ActorContextManager::class)->current()->originator;
    }
}
