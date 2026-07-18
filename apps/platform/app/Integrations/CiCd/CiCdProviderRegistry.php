<?php

namespace App\Integrations\CiCd;

use App\Enums\IntegrationProvider;
use App\Exceptions\UnsupportedCiCdProvider;
use App\Integrations\CiCd\Contracts\CiCdProvider;

class CiCdProviderRegistry
{
    /** @var array<string, CiCdProvider> */
    private array $providers = [];

    /** @param iterable<CiCdProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(CiCdProvider $provider): void
    {
        $this->providers[$provider->provider()->value] = $provider;
    }

    public function for(IntegrationProvider|string $provider): CiCdProvider
    {
        $provider = is_string($provider) ? IntegrationProvider::from($provider) : $provider;

        return $this->providers[$provider->value] ?? throw UnsupportedCiCdProvider::for($provider);
    }
}
