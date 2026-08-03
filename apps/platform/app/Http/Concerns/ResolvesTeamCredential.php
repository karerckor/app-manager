<?php

namespace App\Http\Concerns;

use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;

trait ResolvesTeamCredential
{
    /**
     * Resolve a credential that belongs to the team and is reachable from
     * the given app through its identifiers or profiles. 404s otherwise,
     * so a credential attached only to a different app in the same team
     * cannot be read or mutated through this app's URL.
     *
     * @param  array<int|string, mixed>  $with
     */
    protected function resolveTeamCredential(Team $team, MobileApp $mobileApp, string $id, array $with = []): Credential
    {
        return $team->credentials()
            ->whereKey($id)
            ->where(fn ($query) => $query
                ->whereHas('appIdentifiers', fn ($relation) => $relation->where('mobile_app_id', $mobileApp->id))
                ->orWhereHas('profiles.appIdentifier', fn ($relation) => $relation->where('mobile_app_id', $mobileApp->id)))
            ->with($with)
            ->firstOrFail();
    }
}
