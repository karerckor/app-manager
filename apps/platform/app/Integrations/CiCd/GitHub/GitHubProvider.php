<?php

namespace App\Integrations\CiCd\GitHub;

use App\Enums\IntegrationProvider;
use App\Integrations\CiCd\Contracts\CiCdProvider;
use App\Integrations\CiCd\Data\CancelResult;
use App\Integrations\CiCd\Data\DispatchResult;
use App\Integrations\CiCd\Data\PipelineData;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use App\Integrations\CiCd\Data\RefData;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use DateTimeImmutable;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

class GitHubProvider implements CiCdProvider
{
    public function __construct(
        private readonly GitHubClient $client,
        private readonly GitHubWorkflowDefinition $workflowDefinition,
    ) {}

    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::GitHub;
    }

    public function repositories(Integration $integration): array
    {
        return collect($this->client->repositories($this->installationId($integration)))
            ->map(fn (array $repository) => new RepositoryData(
                id: (string) $repository['id'],
                name: (string) $repository['name'],
                fullName: (string) $repository['full_name'],
                defaultBranch: (string) ($repository['default_branch'] ?? 'main'),
                private: (bool) ($repository['private'] ?? false),
                url: (string) ($repository['html_url'] ?? ''),
            ))
            ->values()
            ->all();
    }

    public function pipelines(Integration $integration, RepositoryData $repository): array
    {
        return collect($this->client->workflows($this->installationId($integration), $repository->fullName))
            ->filter(fn (array $workflow) => ($workflow['state'] ?? null) === 'active')
            ->map(function (array $workflow) use ($integration, $repository) {
                try {
                    $yaml = $this->client->workflowContents(
                        $this->installationId($integration),
                        $repository->fullName,
                        (string) $workflow['path'],
                        $repository->defaultBranch,
                    );
                } catch (RequestException|RuntimeException) {
                    return null;
                }

                if (! $this->workflowDefinition->supportsDispatch($yaml)) {
                    return null;
                }

                return new PipelineData(
                    id: (string) $workflow['id'],
                    name: (string) $workflow['name'],
                    path: (string) $workflow['path'],
                    state: (string) $workflow['state'],
                    url: (string) ($workflow['html_url'] ?? ''),
                    inputs: $this->workflowDefinition->dispatchInputs($yaml),
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    public function refs(Integration $integration, RepositoryData $repository): array
    {
        $installationId = $this->installationId($integration);
        $branches = collect($this->client->branches($installationId, $repository->fullName))
            ->map(fn (array $branch) => new RefData(
                name: (string) $branch['name'],
                type: 'branch',
                sha: (string) data_get($branch, 'commit.sha', ''),
            ));
        $tags = collect($this->client->tags($installationId, $repository->fullName))
            ->map(fn (array $tag) => new RefData(
                name: (string) $tag['name'],
                type: 'tag',
                sha: (string) data_get($tag, 'commit.sha', ''),
            ));

        return $branches->concat($tags)->values()->all();
    }

    public function dispatch(CiCdPipeline $pipeline, string $ref, array $inputs): DispatchResult
    {
        $response = $this->client->dispatchWorkflow(
            $this->installationId($pipeline->integration),
            $pipeline->repository_full_name,
            $pipeline->workflow_id,
            $ref,
            $inputs,
        );
        $runId = $response['workflow_run_id'] ?? null;
        $apiUrl = $response['run_url'] ?? null;
        $htmlUrl = $response['html_url'] ?? null;

        if (! is_int($runId) && ! is_string($runId)) {
            throw new RuntimeException('GitHub workflow dispatch did not return a run ID.');
        }

        if (! is_string($apiUrl) || ! is_string($htmlUrl)) {
            throw new RuntimeException('GitHub workflow dispatch did not return run URLs.');
        }

        return new DispatchResult((string) $runId, $apiUrl, $htmlUrl);
    }

    public function cancel(CiCdRun $run): CancelResult
    {
        $status = $this->client->cancelWorkflowRun(
            $this->installationId($run->integration),
            $run->pipeline->repository_full_name,
            (string) $run->external_run_id,
        );

        return new CancelResult($status === 202, $status === 409);
    }

    public function status(CiCdRun $run): ProviderRunStatus
    {
        $response = $this->client->workflowRun(
            $this->installationId($run->integration),
            $run->pipeline->repository_full_name,
            (string) $run->external_run_id,
        );

        return new ProviderRunStatus(
            runId: (string) $response['id'],
            status: (string) $response['status'],
            conclusion: isset($response['conclusion']) ? (string) $response['conclusion'] : null,
            htmlUrl: (string) ($response['html_url'] ?? ''),
            startedAt: $this->date($response['run_started_at'] ?? null),
            completedAt: ($response['status'] ?? null) === 'completed'
                ? $this->date($response['updated_at'] ?? null)
                : null,
        );
    }

    private function installationId(Integration $integration): string
    {
        if (! is_string($integration->external_id) || $integration->external_id === '') {
            throw new RuntimeException('GitHub integration has no installation ID.');
        }

        return $integration->external_id;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
