<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Team $team): Response
    {
        $user = $request->user();
        abort_unless($user->teams()->whereKey($team->id)->exists(), 403);

        $passwordConfirmed = (time() - $request->session()->get('auth.password_confirmed_at', 0))
            < config('auth.password_timeout', 10800);

        $apps = $team?->mobileApps()
            ->with('media')
            ->withCount('appIdentifiers')
            ->latest('updated_at')
            ->get()
            ->map(fn ($app) => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'description' => $app->description,
                'url' => route('accounts.apps.show', ['team' => $team, 'mobileApp' => $app]),
                'icon_url' => $app->hasMedia('app_icon')
                    ? route('accounts.apps.assets.preview', ['team' => $team, 'mobileApp' => $app, 'collection' => 'app_icon'])
                    : null,
                'identifiers_count' => $app->app_identifiers_count,
                'updated_at' => $app->updated_at,
            ]) ?? collect();

        return Inertia::render('Dashboard', [
            'teams' => $user->teams()->select('teams.id', 'teams.name', 'teams.slug', 'teams.owner_id')->get(),
            'currentTeam' => $team->only('id', 'name', 'slug'),
            'createAppUrl' => route('accounts.apps.store', ['team' => $team]),
            'apps' => $apps,
            'stats' => [
                'apps' => $apps->count(),
                'identifiers' => $apps->sum('identifiers_count'),
                'credentials' => $team?->credentials()->count() ?? 0,
            ],
            'twoFactorEnabled' => ! is_null($user->two_factor_confirmed_at),
            'twoFactorPending' => ! is_null($user->two_factor_secret) && is_null($user->two_factor_confirmed_at),
            'twoFactorQrCode' => $passwordConfirmed && $user->two_factor_secret ? $user->twoFactorQrCodeSvg() : null,
            'recoveryCodes' => $passwordConfirmed && $user->two_factor_secret ? $user->recoveryCodes() : [],
        ]);
    }
}
