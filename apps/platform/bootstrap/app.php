<?php

use App\Http\Middleware\EnsureTeamMember;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is('api/v1/*/apps/*/builds/*/logs'),
        ]);

        $middleware->preventRequestForgery(except: [
            'webhooks/github',
        ]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'team.member' => EnsureTeamMember::class,
            'team.permission' => \App\Http\Middleware\EnsureTeamPermission::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            \App\Http\Middleware\SetActorContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
