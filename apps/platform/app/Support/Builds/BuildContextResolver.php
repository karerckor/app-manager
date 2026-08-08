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
    public function __construct(
        private readonly AppIdentifierResolver $identifiers,
    ) {}

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

        $target = $this->identifiers->resolve($mobileApp, $platform, $identifier);
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
