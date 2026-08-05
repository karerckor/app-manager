<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\SentryDsnRequest;
use App\Integrations\Sentry\SentryDsnManager;
use App\Models\SentryDsn;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SentryDsnController extends Controller
{
    public function __construct(private readonly SentryDsnManager $manager) {}

    public function store(SentryDsnRequest $request, Team $team): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $dsn = $this->manager->create($team, $request->user(), $request->sentryData());

        return back()->with('status', "Sentry DSN {$dsn->integration->name} created.");
    }

    public function update(SentryDsnRequest $request, Team $team, SentryDsn $sentryDsn): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $this->assertDsn($team, $sentryDsn);
        $this->manager->update($sentryDsn, $request->user(), $request->sentryData());

        return back()->with('status', 'Sentry DSN updated.');
    }

    public function destroy(Request $request, Team $team, SentryDsn $sentryDsn): RedirectResponse
    {
        $this->ensureEnabled();
        Gate::forUser($request->user())->authorize('manageIntegrations', $team);
        $this->assertDsn($team, $sentryDsn);
        $this->manager->delete($sentryDsn, $request->user());

        return back()->with('status', 'Sentry DSN deleted.');
    }

    private function assertDsn(Team $team, SentryDsn $dsn): void
    {
        abort_unless($dsn->team_id === $team->id, 404);
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('integrations.enabled') && config('integrations.sentry.enabled'), 404);
    }
}
