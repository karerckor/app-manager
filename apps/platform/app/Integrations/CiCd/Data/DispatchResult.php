<?php

namespace App\Integrations\CiCd\Data;

final readonly class DispatchResult
{
    public function __construct(
        public string $runId,
        public string $apiUrl,
        public string $htmlUrl,
    ) {}
}
