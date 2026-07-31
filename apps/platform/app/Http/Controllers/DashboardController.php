<?php

namespace App\Http\Controllers;

use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
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

        $search = trim($request->string('search')->toString());
        $appsQuery = $team->mobileApps();
        $driver = $appsQuery->getConnection()->getDriverName();

        $apps = $appsQuery
            ->with('media')
            ->withCount('appIdentifiers')
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search, $driver) {
                $pattern = "%{$search}%";

                $query
                    ->whereLike('name', $pattern)
                    ->orWhereLike('slug', $pattern)
                    ->orWhereLike('description', $pattern);

                if ($driver === 'pgsql') {
                    $query->orWhereRaw(
                        "EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE((mobile_apps.tags)::jsonb, '[]'::jsonb)) tag WHERE tag->>0 ILIKE ?)",
                        [$pattern],
                    );
                } elseif ($driver === 'sqlite') {
                    $query->orWhereRaw(
                        "EXISTS (SELECT 1 FROM json_each(COALESCE(mobile_apps.tags, '[]')) tag WHERE json_extract(tag.value, '\$[0]') LIKE ? COLLATE NOCASE)",
                        [$pattern],
                    );
                } else {
                    $query->orWhereLike('tags', $pattern);
                }
            }))
            ->latest('updated_at')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (MobileApp $app) => [
                'id' => $app->id,
                'name' => $app->name,
                'slug' => $app->slug,
                'description' => $app->description,
                'tags' => $app->tags ?? [],
                'url' => route('accounts.apps.show', ['team' => $team, 'mobileApp' => $app]),
                'icon_url' => $app->hasMedia('app_icon')
                    ? route('accounts.apps.assets.preview', ['team' => $team, 'mobileApp' => $app, 'collection' => 'app_icon'])
                    : null,
                'identifiers_count' => $app->app_identifiers_count,
                'updated_at' => $app->updated_at,
            ]);

        return Inertia::render('Dashboard', [
            'teams' => $user->teams()->select('teams.id', 'teams.name', 'teams.slug', 'teams.owner_id')->get(),
            'currentTeam' => $team->only('id', 'name', 'slug'),
            'createAppUrl' => route('accounts.apps.store', ['team' => $team]),
            'apps' => $apps,
            'filters' => [
                'search' => $search,
            ],
            'stats' => [
                'apps' => $team->mobileApps()->count(),
                'identifiers' => AppIdentifier::query()
                    ->whereHas('mobileApp', fn (Builder $query) => $query->where('team_id', $team->id))
                    ->count(),
                'credentials' => $team->credentials()->count(),
            ],
            'twoFactorEnabled' => ! is_null($user->two_factor_confirmed_at),
            'twoFactorPending' => ! is_null($user->two_factor_secret) && is_null($user->two_factor_confirmed_at),
            'twoFactorQrCode' => $passwordConfirmed && $user->two_factor_secret ? $user->twoFactorQrCodeSvg() : null,
            'recoveryCodes' => $passwordConfirmed && $user->two_factor_secret ? $user->recoveryCodes() : [],
        ]);
    }
}
