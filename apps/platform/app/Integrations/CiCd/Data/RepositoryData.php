<?php

namespace App\Integrations\CiCd\Data;

final readonly class RepositoryData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $fullName,
        public string $defaultBranch,
        public bool $private,
        public string $url,
        public ?string $mobileAppId = null,
        public string $readiness = 'ready',
        public ?string $readinessMessage = null,
        public ?string $sourceRepository = null,
        public array $metadata = [],
    ) {}
}
