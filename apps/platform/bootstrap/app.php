<?php

use App\Http\Middleware\EnsureOwnsModel;
use App\Http\Middleware\EnsureSelfHostedInstallation;
use App\Http\Middleware\EnsureTeamMember;
use App\Http\Middleware\EnsureTeamPermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetActorContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
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
            'owns.model' => EnsureOwnsModel::class,
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
    ->booted(function (): void {
        // Config isn't loaded yet inside withMiddleware() — both the console
        // kernel path tests boot through and the HTTP kernel path production
        // requests boot through resolve that closure before the config
        // bootstrapper runs. Read app.trusted_proxies here instead, once the
        // application has actually booted, and set the same static state
        // that Middleware::trustProxies() would have set.
        $trustedProxies = trim((string) config('app.trusted_proxies'));

        if ($trustedProxies !== '') {
            TrustProxies::at(
                $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)),
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'access_token',
            'bot_token',
            'current_password',
            'github.client_secret',
            'github.private_key',
            'gitlab.client_secret',
            'password',
            'password_confirmation',
            'payload.key_password',
            'payload.password',
            'payload.store_password',
            'storage.s3.secret_access_key',
            'storage.s3.session_token',
            'webhook_url',
        ]);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
