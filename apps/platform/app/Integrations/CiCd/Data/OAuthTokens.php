<?php

namespace App\Integrations\CiCd\Data;

use DateTimeImmutable;

final readonly class OAuthTokens
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public string $tokenType = 'Bearer',
        public array $scopes = [],
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function expiresSoon(int $leewaySeconds = 60): bool
    {
        return $this->expiresAt !== null
            && $this->expiresAt->getTimestamp() <= time() + $leewaySeconds;
    }
}
