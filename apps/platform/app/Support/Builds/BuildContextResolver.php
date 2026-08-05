<?php

namespace App\Support\Builds;

use App\Enums\Platform;
use App\Enums\ProfileType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Profile;
use App\Support\Credentials\BuildProfileSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class BuildContextResolver
{
    public function resolve(
        MobileApp $mobileApp,
        Platform $platform,
        ProfileType $profileType,
        ?string $identifier = null,
    ): array {
        $profileDefinition = BuildProfileSchema::definition($profileType, $platform);

        if ($profileDefinition === null) {
            throw ValidationException::withMessages([
                'profile_type' => 'This build target cannot be used with the selected platform.',
            ]);
        }

        $target = $this->target($mobileApp, $platform, $identifier);
        $profile = $target->profiles()
            ->where('type', $profileType->value)
            ->first();

        return [
            'target' => $target,
            'profile' => $profile,
            'profile_definition' => $profileDefinition,
            'credentials' => $this->credentials($mobileApp, $target, $profile),
        ];
    }

    private function target(
        MobileApp $mobileApp,
        Platform $platform,
        ?string $identifier,
    ): AppIdentifier {
        $query = $mobileApp->appIdentifiers()
            ->where('platform', $platform->value);

        if ($identifier !== null) {
            return (clone $query)
                ->where('identifier', $identifier)
                ->firstOrFail();
        }

        $default = (clone $query)
            ->where('is_default', true)
            ->first();

        if ($default) {
            return $default;
        }

        $candidates = $query
            ->orderBy('identifier')
            ->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages([
                'identifier' => "No {$platform->value} identifier is configured for this application.",
            ]);
        }

        throw ValidationException::withMessages([
            'identifier' => 'Multiple identifiers match; select one explicitly: '
                .$candidates->pluck('identifier')->join(', '),
        ]);
    }

    private function credentials(
        MobileApp $mobileApp,
        AppIdentifier $target,
        ?Profile $profile,
    ): Collection {
        $credentialIds = $target->credentials()
            ->pluck('credentials.id');

        if ($profile) {
            $credentialIds = $credentialIds->merge(
                $profile->credentials()->pluck('credentials.id'),
            );
        }

        return Credential::query()
            ->where('team_id', $mobileApp->team_id)
            ->whereKey($credentialIds->unique()->values())
            ->with([
                'media',
                'appIdentifiers' => fn ($query) => $query
                    ->whereKey($target->id),
                'profiles' => function ($query) use ($profile) {
                    if ($profile) {
                        $query->whereKey($profile->id)->with('appIdentifier');

                        return;
                    }

                    $query->whereRaw('1 = 0');
                },
            ])
            ->orderBy('type')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
