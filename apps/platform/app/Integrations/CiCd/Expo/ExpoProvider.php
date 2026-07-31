<?php

namespace App\Integrations\CiCd\Expo;

use App\Enums\IntegrationProvider;
use App\Integrations\CiCd\AbstractCiCdProvider;
use App\Integrations\CiCd\Data\CancelResult;
use App\Integrations\CiCd\Data\DispatchResult;
use App\Integrations\CiCd\Data\PipelineData;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use App\Integrations\CiCd\Data\RefData;
use App\Integrations\CiCd\Data\RepositoryData;
use App\Integrations\Expo\EasWorkflowDefinition;
use App\Integrations\Expo\ExpoClient;
use App\Models\CiCdPipeline;
use App\Models\CiCdRun;
use App\Models\ExpoProject;
use App\Models\Integration;
use RuntimeException;

class ExpoProvider extends AbstractCiCdProvider
{
    private const MAX_LOG_BYTES = 500_000;

    public function __construct(
        private readonly ExpoClient $client,
        private readonly EasWorkflowDefinition $workflowDefinition,
    ) {}

    public function provider(): IntegrationProvider
    {
        return IntegrationProvider::Expo;
    }

    public function repositories(Integration $integration): array
    {
        return $integration->expoProjects()
            ->whereNotNull('mobile_app_id')
            ->where('exists_on_expo', true)
            ->orderBy('name')
            ->get()
            ->map(function (ExpoProject $project) use ($integration) {
                $remote = $this->client->workflowConfiguration(
                    $integration,
                    $project->external_id,
                );
                $github = is_array($remote['githubRepository'] ?? null)
                    ? $remote['githubRepository']
                    : null;
                $workflows = collect((array) ($remote['workflows'] ?? []))
                    ->filter(fn ($workflow) => is_array($workflow) && filled($workflow['fileName'] ?? null))
                    ->map(function (array $workflow) {
                        $edges = array_values((array) data_get(
                            $workflow,
                            'revisionsPaginated.edges',
                            [],
                        ));
                        $revision = $edges === []
                            ? null
                            : data_get($edges[array_key_last($edges)], 'node');
                        $yaml = is_array($revision) && is_string($revision['yamlConfig'] ?? null)
                            ? $revision['yamlConfig']
                            : '';

                        return [
                            'id' => (string) ($workflow['id'] ?? $workflow['fileName']),
                            'name' => (string) ($workflow['name'] ?? $workflow['fileName']),
                            'file_name' => (string) $workflow['fileName'],
                            'inputs' => $this->workflowDefinition->dispatchInputs($yaml),
                        ];
                    })
                    ->values()
                    ->all();
                $branches = collect((array) data_get(
                    $remote,
                    'workflowRunGitBranchesPaginated.edges',
                    [],
                ))
                    ->map(fn ($edge) => data_get($edge, 'node.name'))
                    ->filter(fn ($branch) => is_string($branch) && $branch !== '');
                $defaultBranch = is_array($github)
                    ? (string) data_get($github, 'metadata.defaultBranch', '')
                    : '';

                if ($defaultBranch !== '') {
                    $branches->prepend($defaultBranch);
                }

                [$readiness, $message] = $this->readiness($github, $workflows);
                $githubOwner = is_array($github)
                    ? (string) data_get($github, 'metadata.githubRepoOwnerName', '')
                    : '';
                $githubName = is_array($github)
                    ? (string) data_get($github, 'metadata.githubRepoName', '')
                    : '';
                $sourceRepository = $githubOwner !== '' && $githubName !== ''
                    ? "{$githubOwner}/{$githubName}"
                    : null;

                return new RepositoryData(
                    id: $project->external_id,
                    name: $project->name,
                    fullName: $project->full_name,
                    defaultBranch: $defaultBranch,
                    private: (bool) data_get($github, 'metadata.private', true),
                    url: $this->projectUrl($project),
                    mobileAppId: $project->mobile_app_id,
                    readiness: $readiness,
                    readinessMessage: $message,
                    sourceRepository: $sourceRepository,
                    metadata: [
                        'workflows' => $workflows,
                        'branches' => $branches->unique()->values()->all(),
                    ],
                );
            })
            ->values()
            ->all();
    }

    public function pipelines(Integration $integration, RepositoryData $repository): array
    {
        if ($repository->readiness !== 'ready') {
            return [];
        }

        return collect((array) ($repository->metadata['workflows'] ?? []))
            ->map(fn (array $workflow) => new PipelineData(
                id: (string) $workflow['id'],
                name: (string) $workflow['name'],
                path: '.eas/workflows/'.(string) $workflow['file_name'],
                state: 'active',
                url: rtrim($repository->url, '/').'/workflows',
                inputs: (array) ($workflow['inputs'] ?? []),
            ))
            ->values()
            ->all();
    }

    public function refs(Integration $integration, RepositoryData $repository): array
    {
        if ($repository->readiness === 'github_not_connected') {
            return [];
        }

        return collect((array) ($repository->metadata['branches'] ?? []))
            ->map(fn (string $branch) => new RefData(
                name: $branch,
                type: 'branch',
                sha: '',
            ))
            ->values()
            ->all();
    }

    public function dispatch(CiCdPipeline $pipeline, string $ref, array $inputs): DispatchResult
    {
        $project = ExpoProject::query()
            ->where('integration_id', $pipeline->integration_id)
            ->where('mobile_app_id', $pipeline->mobile_app_id)
            ->where('external_id', $pipeline->repository_id)
            ->where('exists_on_expo', true)
            ->first();

        if (! $project) {
            throw new RuntimeException('The EAS pipeline is no longer linked to this application.');
        }

        $response = $this->client->dispatchWorkflow(
            $pipeline->integration,
            $project->external_id,
            $ref,
            basename($pipeline->workflow_path),
            $inputs,
        );
        $runId = $response['id'] ?? null;
        $htmlUrl = $response['url'] ?? null;

        if (! is_string($runId) || $runId === '' || ! is_string($htmlUrl) || $htmlUrl === '') {
            throw new RuntimeException('Expo workflow dispatch did not return a run ID and URL.');
        }

        return new DispatchResult(
            $runId,
            rtrim((string) config('integrations.expo.api_url'), '/')
                .'/v2/workflows/runs/'.rawurlencode($runId),
            $htmlUrl,
        );
    }

    public function cancel(CiCdRun $run): CancelResult
    {
        $current = $this->client->workflowRun($run->integration, $this->runId($run));

        if ($this->terminal((string) ($current['status'] ?? ''))) {
            return new CancelResult(false, true);
        }

        $cancelled = $this->client->cancelWorkflowRun(
            $run->integration,
            $this->runId($run),
        );

        return new CancelResult(
            strtoupper((string) ($cancelled['status'] ?? '')) === 'CANCELED',
        );
    }

    public function status(CiCdRun $run): ProviderRunStatus
    {
        $response = $this->client->workflowRun($run->integration, $this->runId($run));
        [$status, $conclusion] = $this->normalizeStatus((string) ($response['status'] ?? ''));

        return new ProviderRunStatus(
            runId: (string) ($response['id'] ?? $this->runId($run)),
            status: $status,
            conclusion: $conclusion,
            htmlUrl: (string) ($response['url'] ?? $run->external_run_url ?? ''),
            startedAt: $status === 'in_progress' || $status === 'completed'
                ? $this->date($response['createdAt'] ?? null)
                : null,
            completedAt: $status === 'completed'
                ? $this->date($response['updatedAt'] ?? null)
                : null,
        );
    }

    public function logs(CiCdRun $run): string
    {
        $response = $this->client->workflowRun($run->integration, $this->runId($run));
        $lines = [
            'EAS Workflow: '.(string) ($response['id'] ?? $this->runId($run)),
            'Status: '.(string) ($response['status'] ?? 'unknown'),
            'Git ref: '.(string) ($response['requestedGitRef'] ?? 'unknown'),
        ];

        foreach ((array) ($response['jobs'] ?? []) as $job) {
            if (! is_array($job)) {
                continue;
            }

            $label = (string) ($job['name'] ?? $job['key'] ?? $job['id'] ?? 'job');
            $lines[] = '';
            $lines[] = "===== {$label} =====";
            $lines[] = 'Type: '.(string) ($job['type'] ?? 'unknown');
            $lines[] = 'Status: '.(string) ($job['status'] ?? 'unknown');

            if (filled($job['buildId'] ?? null)) {
                $lines[] = 'EAS build: '.(string) $job['buildId'];
            }

            if (filled($job['submissionId'] ?? null)) {
                $lines[] = 'EAS submission: '.(string) $job['submissionId'];
            }

            foreach ((array) ($job['errors'] ?? []) as $error) {
                if (is_array($error) && filled($error['message'] ?? null)) {
                    $lines[] = 'Error: '.(string) $error['message'];
                }
            }
        }

        foreach ((array) ($response['errors'] ?? []) as $error) {
            if (is_array($error) && filled($error['message'] ?? null)) {
                $lines[] = 'Workflow error: '.(string) $error['message'];
            }
        }

        return $this->normalizeLogs(
            implode("\n", $lines),
            '[Earlier EAS Workflow summary truncated]',
            self::MAX_LOG_BYTES,
        );
    }

    /** @return array{string, string|null} */
    private function normalizeStatus(string $status): array
    {
        return match (strtolower($status)) {
            'in-progress', 'in_progress', 'action-required', 'action_required' => ['in_progress', null],
            'success' => ['completed', 'success'],
            'failure' => ['completed', 'failure'],
            'canceled', 'cancelled' => ['completed', 'cancelled'],
            default => ['queued', null],
        };
    }

    private function terminal(string $status): bool
    {
        return in_array(strtolower($status), ['success', 'failure', 'canceled', 'cancelled'], true);
    }

    private function runId(CiCdRun $run): string
    {
        if (! is_string($run->external_run_id) || $run->external_run_id === '') {
            throw new RuntimeException('Expo workflow run has no external run ID.');
        }

        return $run->external_run_id;
    }

    /** @return array{string, string} */
    private function readiness(?array $github, array $workflows): array
    {
        if ($github === null) {
            return [
                'github_not_connected',
                'This Expo project is linked to App Manager, but no GitHub repository is connected in EAS.',
            ];
        }

        if ($workflows === []) {
            return [
                'workflows_not_configured',
                'GitHub is connected in EAS, but no EAS Workflows are available for this project.',
            ];
        }

        return ['ready', 'EAS Workflows are ready to configure.'];
    }

    private function projectUrl(ExpoProject $project): string
    {
        return rtrim((string) config('integrations.expo.web_url'), '/')
            .'/accounts/'.rawurlencode($project->account_name)
            .'/projects/'.rawurlencode($project->slug);
    }
}
