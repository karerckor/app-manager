<?php

use App\Http\Middleware\EnsureBillingEnabled;
use App\Http\Middleware\EnsureSelfHostedInstallation;
use App\Http\Middleware\EnsureTeamMember;
use App\Http\Middleware\EnsureTeamPermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetActorContext;
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
            'stripe/webhook',
            'webhooks/github',
        ]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'billing.enabled' => EnsureBillingEnabled::class,
            'team.member' => EnsureTeamMember::class,
            'team.permission' => EnsureTeamPermission::class,
        ]);

        $middleware->web(
            prepend: [
                EnsureSelfHostedInstallation::class,
            ],
            append: [
                HandleInertiaRequests::class,
                SetActorContext::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'access_token',
            'storage.s3.secret_access_key',
            'storage.s3.session_token',
        ]);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
