<?php

namespace App\Integrations\CiCd\GitLab;

use App\Enums\IntegrationProvider;
use App\Integrations\CiCd\AbstractCiCdProvider;
use App\Integrations\CiCd\Data\CancelResult;
use App\Integrations\CiCd\Data\DispatchResult;
use App\Integrations\CiCd\Data\PipelineData;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use App\Integrations\CiCd\Data\RefData;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\Integration;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

class GitLabProvider extends AbstractCiCdProvider
{
    private const MAX_LOG_BYTES = 500_000;

    private const MAX_TRACE_BYTES = 5_000_000;

    public function __construct(
        private readonly GitLabClient $client,
        private readonly GitLabPipelineDefinition $pipelineDefinition,
    ) {}

    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::GitLab;
    }

    public function repositories(Integration $integration): array
    {
        return collect($this->client->projects($integration))
            ->filter(fn (array $project) => isset($project['id'], $project['path_with_namespace']))
            ->map(fn (array $project) => new RepositoryData(
                id: (string) $project['id'],
                name: (string) ($project['name'] ?? $project['path'] ?? $project['id']),
                fullName: (string) $project['path_with_namespace'],
                defaultBranch: (string) ($project['default_branch'] ?? 'main'),
                private: ($project['visibility'] ?? 'private') !== 'public',
                url: (string) ($project['web_url'] ?? ''),
            ))
            ->values()
            ->all();
    }

    public function pipelines(Integration $integration, RepositoryData $repository): array
    {
        try {
            $project = $this->client->project($integration, $repository->id);
            $path = is_string($project['ci_config_path'] ?? null) && $project['ci_config_path'] !== ''
                ? $project['ci_config_path']
                : '.gitlab-ci.yml';
            $yaml = $this->client->repositoryFile(
                $integration,
                $repository->id,
                $path,
                $repository->defaultBranch,
            );
        } catch (RequestException|RuntimeException) {
            return [];
        }

        $projectUrl = (string) ($project['web_url'] ?? $repository->url);

        return [new PipelineData(
            id: 'gitlab-ci',
            name: 'GitLab CI',
            path: $path,
            state: 'active',
            url: $projectUrl === '' ? '' : rtrim($projectUrl, '/').'/-/pipelines/new',
            inputs: $this->pipelineDefinition->inputs($yaml),
        )];
    }

    public function refs(Integration $integration, RepositoryData $repository): array
    {
        $branches = collect($this->client->branches($integration, $repository->id))
            ->map(fn (array $branch) => new RefData(
                name: (string) $branch['name'],
                type: 'branch',
                sha: (string) data_get($branch, 'commit.id', ''),
            ));
        $tags = collect($this->client->tags($integration, $repository->id))
            ->map(fn (array $tag) => new RefData(
                name: (string) $tag['name'],
                type: 'tag',
                sha: (string) data_get($tag, 'commit.id', ''),
            ));

        return $branches->concat($tags)->values()->all();
    }

    public function dispatch(CiCdPipeline $pipeline, string $ref, array $inputs): DispatchResult
    {
        $response = $this->client->createPipeline(
            $pipeline->integration,
            $pipeline->repository_id,
            $ref,
            $inputs,
        );
        $runId = $response['id'] ?? null;
        $htmlUrl = $response['web_url'] ?? null;

        if ((! is_int($runId) && ! is_string($runId)) || ! is_string($htmlUrl)) {
            throw new RuntimeException('GitLab pipeline creation did not return a run ID and URL.');
        }

        $apiUrl = $this->client->apiUrl($pipeline->integration)
            .'/projects/'.rawurlencode($pipeline->repository_id)
            .'/pipelines/'.rawurlencode((string) $runId);

        return new DispatchResult((string) $runId, $apiUrl, $htmlUrl);
    }

    public function cancel(CiCdRun $run): CancelResult
    {
        $runId = $this->runId($run);
        $current = $this->client->pipeline($run->integration, $run->pipeline->repository_id, $runId);

        if ($this->terminal((string) ($current['status'] ?? ''))) {
            return new CancelResult(false, true);
        }

        $response = $this->client->cancelPipeline(
            $run->integration,
            $run->pipeline->repository_id,
            $runId,
        );

        return new CancelResult(in_array(($response['status'] ?? null), ['canceling', 'canceled'], true));
    }

    public function status(CiCdRun $run): ProviderRunStatus
    {
        $response = $this->client->pipeline(
            $run->integration,
            $run->pipeline->repository_id,
            $this->runId($run),
        );
        [$status, $conclusion] = $this->normalizeStatus((string) ($response['status'] ?? ''));

        return new ProviderRunStatus(
            runId: (string) $response['id'],
            status: $status,
            conclusion: $conclusion,
            htmlUrl: (string) ($response['web_url'] ?? ''),
            startedAt: $this->date($response['started_at'] ?? null),
            completedAt: $status === 'completed' ? $this->date($response['finished_at'] ?? null) : null,
        );
    }

    public function logs(CiCdRun $run): string
    {
        $runId = $this->runId($run);
        $logs = [];
        $totalBytes = 0;

        foreach ($this->client->pipelineJobs($run->integration, $run->pipeline->repository_id, $runId) as $job) {
            if (! isset($job['id'])) {
                continue;
            }

            try {
                $trace = $this->client->jobTrace(
                    $run->integration,
                    $run->pipeline->repository_id,
                    (string) $job['id'],
                );
            } catch (RequestException $exception) {
                if ($exception->response->status() === 404) {
                    continue;
                }

                throw $exception;
            }

            if ($totalBytes + strlen($trace) > self::MAX_TRACE_BYTES) {
                $trace = substr($trace, 0, max(0, self::MAX_TRACE_BYTES - $totalBytes));
            }

            $totalBytes += strlen($trace);
            $name = (string) ($job['name'] ?? $job['id']);
            $stage = isset($job['stage']) ? ' · '.$job['stage'] : '';
            $logs[] = "===== {$name}{$stage} =====\n{$trace}";

            if ($totalBytes >= self::MAX_TRACE_BYTES) {
                break;
            }
        }

        return $this->normalizeLogs(
            implode("\n\n", $logs),
            '[Earlier GitLab CI log output truncated]',
            self::MAX_LOG_BYTES,
        );
    }

    private function runId(CiCdRun $run): string
    {
        if (! is_string($run->external_run_id) || $run->external_run_id === '') {
            throw new RuntimeException('GitLab pipeline has no external run ID.');
        }

        return $run->external_run_id;
    }

    /** @return array{string, string|null} */
    private function normalizeStatus(string $status): array
    {
        return match ($status) {
            'running' => ['in_progress', null],
            'success' => ['completed', 'success'],
            'failed' => ['completed', 'failure'],
            'canceled' => ['completed', 'cancelled'],
            'skipped' => ['completed', 'neutral'],
            default => ['queued', null],
        };
    }

    private function terminal(string $status): bool
    {
        return in_array($status, ['success', 'failed', 'canceled', 'skipped'], true);
    }
}
