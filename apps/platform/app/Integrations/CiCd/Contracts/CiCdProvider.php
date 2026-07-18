<?php

namespace App\Integrations\CiCd\Contracts;

use App\Enums\IntegrationProvider;
use App\Integrations\CiCd\Data\CancelResult;
use App\Integrations\CiCd\Data\DispatchResult;
use App\Integrations\CiCd\Data\PipelineData;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use App\Integrations\CiCd\Data\RefData;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;

interface CiCdProvider
{
    public function provider(): IntegrationProvider;

    /** @return list<RepositoryData> */
    public function repositories(Integration $integration): array;

    /** @return list<PipelineData> */
    public function pipelines(Integration $integration, RepositoryData $repository): array;

    /** @return list<RefData> */
    public function refs(Integration $integration, RepositoryData $repository): array;

    /** @param array<string, mixed> $inputs */
    public function dispatch(CiCdPipeline $pipeline, string $ref, array $inputs): DispatchResult;

    public function cancel(CiCdRun $run): CancelResult;

    public function status(CiCdRun $run): ProviderRunStatus;
}
