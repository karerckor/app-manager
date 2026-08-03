<?php

namespace App\Enums;

enum CredentialApiProfile: string
{
    case Full = 'full';
    case DashboardOnly = 'dashboard_only';

    public static function current(): self
    {
        return self::tryFrom((string) config('credentials.api_profile')) ?? self::Full;
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
