<?php

namespace App\Http\Middleware;

use App\Support\Audit\ActorContextManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetActorContext
{
    public function __construct(private readonly ActorContextManager $manager) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->manager->set($this->manager->fromRequest($request));

        return $next($request);
    }
}
