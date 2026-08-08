<?php

namespace App\Support\Builds;

use App\Enums\Platform;
use App\Models\AppIdentifier;
use App\Models\MobileApp;
use Illuminate\Validation\ValidationException;

class AppIdentifierResolver
{
    public function resolve(
        MobileApp $mobileApp,
        Platform $platform,
        ?string $identifier = null,
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
            'identifier' => 'Multiple identifiers match; select one explicitly or mark a default identifier: '
                .$candidates->pluck('identifier')->join(', '),
        ]);
    }
}
