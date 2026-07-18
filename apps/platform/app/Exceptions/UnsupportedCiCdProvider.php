<?php

namespace App\Exceptions;

use App\Enums\IntegrationProvider;
use RuntimeException;

class UnsupportedCiCdProvider extends RuntimeException
{
    public static function for(IntegrationProvider $provider): self
    {
        return new self("CI/CD provider [{$provider->value}] is not registered.");
    }
}
