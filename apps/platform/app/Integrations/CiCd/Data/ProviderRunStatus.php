<?php

namespace App\Integrations\CiCd\Data;

use DateTimeImmutable;

final readonly class ProviderRunStatus
{
    public function __construct(
        public string $runId,
        public string $status,
        public ?string $conclusion,
        public string $htmlUrl,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $completedAt = null,
    ) {}
}
