<?php

namespace App\Enums;

use InvalidArgumentException;

enum CredentialApiProfile: string
{
    case Full = 'full';
    case DashboardOnly = 'dashboard_only';

    public static function current(): self
    {
        $configured = trim((string) config('credentials.api_profile'));

        if ($configured === '') {
            return self::Full;
        }

        return self::tryFrom($configured) ?? throw new InvalidArgumentException(
            "Unknown CREDENTIALS_API_PROFILE [{$configured}]. Expected 'full' or 'dashboard_only'."
        );
    }

    /**
     * Whether the HTTP API may carry credential secrets — the file bytes, the
     * decrypted payload, and pushes of either to a third party such as Expo.
     */
    public function exposesSecretMaterial(): bool
    {
        return $this === self::Full;
    }
}
