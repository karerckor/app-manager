<?php

namespace App\Exceptions;

use App\Enums\BuildStatus;
use RuntimeException;

class InvalidBuildTransition extends RuntimeException
{
    public static function from(BuildStatus $from, BuildStatus $to): self
    {
        return new self("Cannot move a build from {$from->value} to {$to->value}.");
    }
}
