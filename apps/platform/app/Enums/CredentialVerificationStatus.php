<?php

namespace App\Enums;

enum CredentialVerificationStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Not verified',
            self::Verified => 'Verified',
            self::Failed => 'Verification failed',
        };
    }
}
