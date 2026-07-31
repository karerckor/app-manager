<?php

namespace App\Integrations\CiCd;

use App\Integrations\CiCd\Concerns\NormalizesProviderLogs;
use App\Integrations\CiCd\Contracts\CiCdProvider;
use App\Models\Integration;
use DateTimeImmutable;
use RuntimeException;

abstract class AbstractCiCdProvider implements CiCdProvider
{
    use NormalizesProviderLogs;

    protected function externalId(Integration $integration, string $description): string
    {
        if (! is_string($integration->external_id) || $integration->external_id === '') {
            throw new RuntimeException($this->provider()->label()." integration has no {$description}.");
        }

        return $integration->external_id;
    }

    protected function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
