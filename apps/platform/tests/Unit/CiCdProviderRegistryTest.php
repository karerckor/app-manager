<?php

namespace Tests\Unit;

use App\Enums\IntegrationProvider;
use App\Exceptions\UnsupportedCiCdProvider;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Integrations\CiCd\Contracts\CiCdProvider;
use App\Integrations\CiCd\Data\CancelResult;
use App\Integrations\CiCd\Data\DispatchResult;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use PHPUnit\Framework\TestCase;

class CiCdProviderRegistryTest extends TestCase
{
    public function test_provider_is_resolved_without_controller_conditionals(): void
    {
        $provider = new class implements CiCdProvider
        {
            public function provider(): IntegrationProvider
            {
                return IntegrationProvider::GitHub;
            }

            public function repositories(Integration $integration): array
            {
                return [];
            }

            public function pipelines(Integration $integration, RepositoryData $repository): array
            {
                return [];
            }

            public function refs(Integration $integration, RepositoryData $repository): array
            {
                return [];
            }

            public function dispatch(CiCdPipeline $pipeline, string $ref, array $inputs): DispatchResult
            {
                return new DispatchResult('1', 'https://api.example/runs/1', 'https://example/runs/1');
            }

            public function cancel(CiCdRun $run): CancelResult
            {
                return new CancelResult(true);
            }

            public function status(CiCdRun $run): ProviderRunStatus
            {
                return new ProviderRunStatus('1', 'completed', 'success', 'https://example/runs/1');
            }

            public function logs(CiCdRun $run): string
            {
                return 'run logs';
            }
        };
        $registry = new CiCdProviderRegistry([$provider]);

        $this->assertSame($provider, $registry->for(IntegrationProvider::GitHub));
        $this->assertSame($provider, $registry->for('github'));
    }

    public function test_unknown_registered_provider_fails_with_typed_exception(): void
    {
        $this->expectException(UnsupportedCiCdProvider::class);

        (new CiCdProviderRegistry)->for(IntegrationProvider::GitHub);
    }
}
