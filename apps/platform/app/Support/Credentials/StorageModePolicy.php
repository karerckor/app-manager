<?php

namespace App\Support\Credentials;

use App\Enums\CredentialStorageMode;
use App\Models\Team;

class StorageModePolicy
{
    /** @return list<CredentialStorageMode> */
    public function instanceModes(): array
    {
        $modes = collect(explode(',', (string) config('credentials.storage_modes')))
            ->map(fn (string $mode): ?CredentialStorageMode => CredentialStorageMode::tryFrom(trim($mode)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $modes === [] ? [CredentialStorageMode::Local] : $modes;
    }

    /** @return list<CredentialStorageMode> */
    public function allowedFor(Team $team): array
    {
        $ceiling = $this->instanceModes();
        $chosen = $team->allowed_credential_storage_modes;

        if (! is_array($chosen)) {
            return $ceiling;
        }

        $selected = collect($chosen)
            ->map(fn ($mode): ?CredentialStorageMode => CredentialStorageMode::tryFrom((string) $mode))
            ->filter();

        $intersection = collect($ceiling)
            ->filter(fn (CredentialStorageMode $mode): bool => $selected->contains($mode))
            ->values()
            ->all();

        // An empty intersection would lock the team out of creating any
        // credential at all, with no in-app way to recover. The instance
        // ceiling stays authoritative instead.
        return $intersection === [] ? $ceiling : $intersection;
    }

    /** @return list<string> */
    public function allowedValuesFor(Team $team): array
    {
        return array_map(
            fn (CredentialStorageMode $mode): string => $mode->value,
            $this->allowedFor($team),
        );
    }

    public function permits(Team $team, CredentialStorageMode $mode): bool
    {
        return in_array($mode, $this->allowedFor($team), true);
    }

    public function defaultFor(Team $team): CredentialStorageMode
    {
        return $this->allowedFor($team)[0];
    }
}
