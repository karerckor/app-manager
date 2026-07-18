<?php

namespace App\Integrations\CiCd\Data;

final readonly class RefData
{
    public function __construct(
        public string $name,
        public string $type,
        public string $sha,
    ) {}
}
