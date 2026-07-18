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
use ZipArchive;

class GitHubProvider implements CiCdProvider
{
    private const MAX_LOG_BYTES = 500_000;

    private const MAX_ARCHIVE_BYTES = 5_000_000;

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
        $status = $this->client->forceCancelWorkflowRun(
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

    public function logs(CiCdRun $run): string
    {
        if ($run->external_run_id === null) {
            throw new RuntimeException('GitHub workflow run has no external run ID.');
        }

        $archive = $this->client->workflowRunLogs(
            $this->installationId($run->integration),
            $run->pipeline->repository_full_name,
            (string) $run->external_run_id,
        );

        return $this->extractLogs($archive);
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

    private function extractLogs(string $archive): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to read GitHub Actions logs.');
        }

        if (strlen($archive) > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('GitHub workflow log archive exceeds the supported size.');
        }

        $path = tempnam(sys_get_temp_dir(), 'github-run-logs-');

        if ($path === false || file_put_contents($path, $archive) === false) {
            throw new RuntimeException('Could not create a temporary GitHub Actions log archive.');
        }

        $zip = new ZipArchive;
        $opened = false;

        try {
            if ($zip->open($path) !== true) {
                throw new RuntimeException('GitHub returned an invalid workflow log archive.');
            }

            $opened = true;

            $fileIndexes = [];
            $combinedLogIndexes = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if (! is_array($stat) || str_ends_with((string) ($stat['name'] ?? ''), '/')) {
                    continue;
                }

                $fileIndexes[] = $index;

                if (preg_match('/^\d+_.+\.txt$/i', (string) ($stat['name'] ?? '')) === 1) {
                    $combinedLogIndexes[] = $index;
                }
            }

            // GitHub currently includes both a combined file per job (for example,
            // 0_Build.txt) and the same output split into files under Build/. Reading
            // both representations duplicates every line in the resulting build log.
            $selectedIndexes = $combinedLogIndexes !== [] ? $combinedLogIndexes : $fileIndexes;
            $logs = [];
            $totalBytes = 0;

            foreach ($selectedIndexes as $index) {
                $stat = $zip->statIndex($index);

                if (! is_array($stat)) {
                    continue;
                }

                $size = (int) ($stat['size'] ?? 0);

                if ($size < 0 || $size > self::MAX_ARCHIVE_BYTES || $totalBytes + $size > self::MAX_ARCHIVE_BYTES) {
                    break;
                }

                $contents = $zip->getFromIndex($index);

                if (! is_string($contents)) {
                    continue;
                }

                $totalBytes += strlen($contents);
                $logs[] = '===== '.($stat['name'] ?? 'job.log')." =====\n".$contents;
            }

            $output = trim(implode("\n\n", $logs));

            if ($output === '') {
                return '';
            }

            $output = preg_replace('/\x1B(?:[@-_]|\[[0-?]*[ -\/]*[@-~])/', '', $output) ?? $output;
            $utf8 = iconv('UTF-8', 'UTF-8//IGNORE', $output);
            $output = is_string($utf8) ? $utf8 : $output;

            if (strlen($output) > self::MAX_LOG_BYTES) {
                $output = "[Earlier GitHub Actions log output truncated]\n".substr($output, -self::MAX_LOG_BYTES);
            }

            return $output;
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }
}
