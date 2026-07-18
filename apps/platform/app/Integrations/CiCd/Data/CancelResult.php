<?php

namespace App\Integrations\CiCd\Data;

final readonly class CancelResult
{
    public function __construct(
        public bool $accepted,
        public bool $alreadyTerminal = false,
    ) {}
}
