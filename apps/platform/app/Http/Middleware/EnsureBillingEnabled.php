<?php

namespace App\Http\Middleware;

use App\Billing\BillingConfiguration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBillingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(BillingConfiguration::enabled(), 404);

        BillingConfiguration::assertReady();

        return $next($request);
    }
}
