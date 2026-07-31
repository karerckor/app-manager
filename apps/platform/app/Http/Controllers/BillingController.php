<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BillingController extends Controller
{
    public function index(Request $request, Team $team): Response
    {
        $this->authorizeOwner($request, $team);

        $subscription = $team->subscription(config('billing.subscription', 'default'));

        return Inertia::render('Billing/Index', [
            'subscription' => [
                'status' => $subscription?->stripe_status ?? 'none',
                'active' => $team->subscribed(config('billing.subscription', 'default')),
                'on_grace_period' => $subscription?->onGracePeriod() ?? false,
            ],
            'usage' => [
                'build_runs_this_month' => $team->billingUsageEvents()
                    ->where('meter_event_name', config('billing.meters.build_runs.event_name'))
                    ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->sum('quantity'),
            ],
            'checkoutUrl' => route('accounts.billing.checkout', $team),
            'portalUrl' => $team->hasStripeId() ? route('accounts.billing.portal', $team) : null,
        ]);
    }

    public function checkout(Request $request, Team $team): SymfonyResponse
    {
        $this->authorizeOwner($request, $team);

        if ($team->subscribed(config('billing.subscription', 'default'))) {
            return redirect()
                ->route('accounts.billing.index', $team)
                ->with('status', 'This team already has an active subscription.');
        }

        $checkout = $team
            ->newSubscription(
                config('billing.subscription', 'default'),
                config('billing.prices.base_monthly'),
            )
            ->meteredPrice(config('billing.prices.build_runs'))
            ->withMetadata(['team_id' => $team->id])
            ->checkout([
                'success_url' => route('accounts.billing.index', $team).'?checkout=success',
                'cancel_url' => route('accounts.billing.index', $team).'?checkout=cancelled',
            ]);

        return Inertia::location($checkout->url);
    }

    public function portal(Request $request, Team $team): SymfonyResponse
    {
        $this->authorizeOwner($request, $team);

        if (! $team->hasStripeId()) {
            return redirect()
                ->route('accounts.billing.index', $team)
                ->with('status', 'Start a subscription before opening the billing portal.');
        }

        return Inertia::location(
            $team->billingPortalUrl(route('accounts.billing.index', $team)),
        );
    }

    private function authorizeOwner(Request $request, Team $team): void
    {
        abort_unless($request->user()?->id === $team->owner_id, 403);
    }
}
