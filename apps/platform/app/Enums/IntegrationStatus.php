<?php

namespace App\Enums;

enum IntegrationStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Degraded = 'degraded';
    case Disabled = 'disabled';
    case Disconnected = 'disconnected';

    public function canDispatch(): bool
    {
        return $this === self::Active;
    }
}
