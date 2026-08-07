<?php

namespace App\Http\Controllers;

use App\Enums\CredentialType;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Enums\Platform;
use App\Enums\TeamPermission;
use App\Models\AppIdentifier;
use App\Models\Integration;
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
        $expoFilters = $this->stringList($request->input('expo', []));
        $autoSync = $request->boolean('auto_sync');
        $appsQuery = $team->mobileApps();
        $canManageApplications = $team->allows($user, TeamPermission::ManageApplications);

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
            ->when($expoFilters !== [], function (Builder $query) use ($expoFilters) {
                $hasAnyConnection = in_array('any_connection', $expoFilters, true);
                $hasNotConnected = in_array('not_connected', $expoFilters, true);
                $hasDetached = in_array('detached', $expoFilters, true);
                $accountIds = array_values(array_diff($expoFilters, ['any_connection', 'not_connected', 'detached']));

                if ($hasAnyConnection) {
                    $query->whereHas('expoProject');
                } else {
                    $query->where(function (Builder $q) use ($hasNotConnected, $hasDetached, $accountIds) {
                        if ($hasNotConnected) {
                            $q->whereDoesntHave('expoProject');
                        }
                        if ($hasDetached) {
                            $q->orWhere(function (Builder $detached) {
                                $connectedExpIds = Integration::query()->connectedExpo()->pluck('id')->toArray();

                                if (empty($connectedExpIds)) {
                                    $detached->whereHas('expoProject');
                                } else {
                                    $detached->whereHas('expoProject', fn (Builder $ep) => $ep
                                        ->where(function (Builder $epWhere) use ($connectedExpIds) {
                                            $epWhere->whereNull('integration_id')
                                                ->orWhereNotIn('integration_id', $connectedExpIds);
                                        }));
                                }
                            });
                        }
                        if ($accountIds !== []) {
                            $q->orWhereHas('expoProject', fn (Builder $ep) => $ep->whereIn('integration_id', $accountIds));
                        }
                    });
                }
            })
            ->when($autoSync, fn (Builder $query) => $query->whereHas('expoProject', fn (Builder $ep) => $ep->where('auto_sync_enabled', true)))
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
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search) {
                $pattern = "%{$search}%";

                $query
                    ->whereLike('name', $pattern)
                    ->orWhereLike('slug', $pattern)
                    ->orWhereLike('description', $pattern)
                    // Laravel generates no `or` variant of a local scope, so the
                    // tag predicate is nested instead of called as orWhereTagLike.
                    ->orWhere(fn (Builder $inner) => $inner->whereTagLike($pattern));
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
            'blueprintOptions' => $team->blueprints()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($blueprint) => ['value' => $blueprint->id, 'label' => $blueprint->name])
                ->values(),
            'apps' => $apps,
            'archivedApps' => $canManageApplications
                ? $team->mobileApps()->onlyTrashed()->latest('deleted_at')->limit(10)->get()
                    ->map(fn (MobileApp $app) => [
                        'id' => $app->id,
                        'name' => $app->name,
                        'slug' => $app->slug,
                        'deleted_at' => $app->deleted_at,
                        'restore_url' => route('accounts.apps.restore', [$team, $app->id]),
                    ])->values()
                : [],
            'filters' => [
                'search' => $search,
                'tags' => $tags,
                'available_platforms' => $availablePlatforms,
                'unavailable_platforms' => $unavailablePlatforms,
                'expo' => $expoFilters,
                'auto_sync' => $autoSync,
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
                'expoAccounts' => Integration::where('provider', IntegrationProvider::Expo->value)
                    ->whereIn('status', [IntegrationStatus::Active->value, IntegrationStatus::Degraded->value])
                    ->whereHas('oauthCredential')
                    ->get(['id', 'name'])
                    ->map(fn (Integration $integration) => ['value' => $integration->id, 'label' => $integration->name])
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
