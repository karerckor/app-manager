<?php

namespace App\Http\Controllers;

use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use App\Models\Team;
use App\Support\Credentials\CredentialSchema;
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
        $tags = $this->stringList($request->input('tags', []));
        $availablePlatforms = $this->platformList($request->input('available_platforms', []));
        $unavailablePlatforms = $this->platformList($request->input('unavailable_platforms', []));
        $credentials = $this->credentialTypeList($request->input('credentials', []));
        $missingCredentials = $this->credentialTypeList($request->input('missing_credentials', []));
        $expo = $request->string('expo')->toString();
        $expo = in_array($expo, ['linked', 'unlinked'], true) ? $expo : '';
        $appsQuery = $team->mobileApps();
        $driver = $appsQuery->getConnection()->getDriverName();

        $apps = $appsQuery
            ->with('media')
            ->withCount('appIdentifiers')
            ->when($tags !== [], fn (Builder $query) => $query->whereAnyTag($tags))
            ->when($availablePlatforms !== [], function (Builder $query) use ($availablePlatforms) {
                foreach ($availablePlatforms as $platform) {
                    $query->whereHas('appIdentifiers', fn (Builder $identifiers) => $identifiers->where('platform', $platform));
                }
            })
            ->when($unavailablePlatforms !== [], function (Builder $query) use ($unavailablePlatforms) {
                foreach ($unavailablePlatforms as $platform) {
                    $query->whereDoesntHave('appIdentifiers', fn (Builder $identifiers) => $identifiers->where('platform', $platform));
                }
            })
            ->when($expo === 'linked', fn (Builder $query) => $query->whereHas('expoProject'))
            ->when($expo === 'unlinked', fn (Builder $query) => $query->whereDoesntHave('expoProject'))
            ->when($credentials !== [], function (Builder $query) use ($credentials) {
                foreach ($credentials as $type) {
                    $query->whereHas('appIdentifiers', fn (Builder $identifiers) => $identifiers
                        ->where(fn (Builder $attachments) => $attachments
                            ->whereHas('credentials', fn (Builder $credentials) => $credentials->where('type', $type))
                            ->orWhereHas('profiles.credentials', fn (Builder $credentials) => $credentials->where('type', $type))));
                }
            })
            ->when($missingCredentials !== [], function (Builder $query) use ($missingCredentials) {
                foreach ($missingCredentials as $type) {
                    $platforms = CredentialSchema::definition(CredentialType::from($type))['platforms'];

                    $query->whereHas('appIdentifiers', fn (Builder $identifiers) => $identifiers
                        ->whereIn('platform', $platforms)
                        ->whereDoesntHave('credentials', fn (Builder $credentials) => $credentials->where('type', $type))
                        ->whereDoesntHave('profiles.credentials', fn (Builder $credentials) => $credentials->where('type', $type)));
                }
            })
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
                'tags' => $tags,
                'available_platforms' => $availablePlatforms,
                'unavailable_platforms' => $unavailablePlatforms,
                'expo' => $expo,
                'credentials' => $credentials,
                'missing_credentials' => $missingCredentials,
            ],
            'filterOptions' => [
                'tags' => $team->mobileApps()
                    ->get(['tags'])
                    ->flatMap(fn (MobileApp $app) => $app->tags ?? [])
                    ->filter(fn ($tag) => filled($tag[0] ?? null))
                    ->map(fn (array $tag) => ['value' => $tag[0], 'label' => $tag[0], 'color' => $tag[1] ?? null])
                    ->unique('value')
                    ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values(),
                'platforms' => collect(Platform::cases())
                    ->map(fn (Platform $platform) => ['value' => $platform->value, 'label' => strtoupper($platform->value)])
                    ->values(),
                'credentials' => collect(CredentialType::cases())
                    ->map(fn (CredentialType $type) => [
                        'value' => $type->value,
                        'label' => CredentialSchema::definition($type)['label'],
                    ])
                    ->values(),
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

    /** @return array<int, string> */
    private function stringList(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function platformList(mixed $values): array
    {
        return collect($this->stringList($values))
            ->filter(fn (string $platform) => in_array($platform, array_column(Platform::cases(), 'value'), true))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function credentialTypeList(mixed $values): array
    {
        return collect($this->stringList($values))
            ->filter(fn (string $type) => CredentialType::tryFrom($type) !== null)
            ->values()
            ->all();
    }
}
