<?php

namespace App\Integrations\CiCd\Data;

final readonly class ConnectedAccount
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $externalAccountId = null,
        public ?string $externalAccountName = null,
        public array $settings = [],
    ) {}
}
