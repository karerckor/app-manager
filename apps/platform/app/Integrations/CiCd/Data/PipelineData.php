<?php

namespace App\Integrations\CiCd\Data;

final readonly class PipelineData
{
    /**
     * @param  array<string, array<string, mixed>>  $inputs
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $path,
        public string $state,
        public string $url,
        public array $inputs = [],
    ) {}
}
