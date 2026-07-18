<?php

namespace App\Integrations\CiCd\Data;

final readonly class RepositoryData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $fullName,
        public string $defaultBranch,
        public bool $private,
        public string $url,
    ) {}
}
