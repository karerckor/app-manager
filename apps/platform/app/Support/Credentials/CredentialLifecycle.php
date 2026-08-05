<?php

namespace App\Support\Credentials;

use App\Models\Credential;

class CredentialLifecycle
{
    public const EXPIRING_WITHIN_DAYS = 30;

    public static function status(Credential $credential): string
    {
        if (! $credential->getFirstMedia('file')) {
            return 'missing_file';
        }

        if ($credential->expires_at?->isPast()) {
            return 'expired';
        }

        if ($credential->expires_at?->lessThanOrEqualTo(now()->addDays(self::EXPIRING_WITHIN_DAYS))) {
            return 'expiring';
        }

        return 'ready';
    }
}
