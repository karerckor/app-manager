<?php

namespace App\Http\Middleware;

use App\Support\Installation\SelfHostedConfiguration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSelfHostedInstallation
{
    public function __construct(
        private readonly SelfHostedConfiguration $configuration,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->configuration->enabled()) {
            return $next($request);
        }

        $installerRoute = $request->routeIs('installer.*');

        if (! $this->configuration->installed()) {
            if ($installerRoute) {
                return $next($request);
            }

            if ($request->expectsJson() || ! $request->isMethod('GET')) {
                return response()->json([
                    'message' => 'This App Manager instance has not been installed yet.',
                    'install_url' => route('installer.index'),
                ], 503);
            }

            return redirect()->route('installer.index');
        }

        $this->configuration->apply();

        if ($installerRoute) {
            return redirect('/');
        }

        if ($request->is('register')) {
            abort(404);
        }

        return $next($request);
    }
}
