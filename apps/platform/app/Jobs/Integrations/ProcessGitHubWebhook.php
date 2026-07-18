<?php

namespace App\Jobs\Integrations;

use App\Actions\Integrations\SyncCiCdRunStatus;
use App\Enums\BuildReportingMode;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use App\Models\CiCdRun;
use App\Models\GitHubWebhookDelivery;
use App\Models\Integration;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessGitHubWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(SyncCiCdRunStatus $sync): void
    {
        DB::transaction(function () use ($sync) {
            $delivery = GitHubWebhookDelivery::query()->lockForUpdate()->findOrFail($this->deliveryId);

            if ($delivery->processed_at !== null) {
                return;
            }

            $payload = $delivery->payload ?? [];

            if ($delivery->event === 'installation') {
                $this->handleInstallation($delivery, $payload);
            } elseif ($delivery->event === 'installation_repositories') {
                Integration::query()
                    ->where('provider', IntegrationProvider::GitHub->value)
                    ->where('external_id', $delivery->installation_id)
                    ->update(['last_synced_at' => now(), 'updated_at' => now()]);
            } elseif ($delivery->event === 'workflow_run') {
                $this->handleWorkflowRun($delivery, $payload, $sync);
            }

            $delivery->update([
                'payload' => null,
                'processed_at' => now(),
            ]);
        });
    }

    /** @param array<string, mixed> $payload */
    private function handleInstallation(GitHubWebhookDelivery $delivery, array $payload): void
    {
        $action = (string) ($payload['action'] ?? '');
        $updates = match ($action) {
            'deleted' => [
                'status' => IntegrationStatus::Disconnected->value,
                'disconnected_at' => now(),
            ],
            'suspend', 'suspended' => [
                'status' => IntegrationStatus::Disabled->value,
                'disabled_at' => now(),
            ],
            'created', 'unsuspend', 'unsuspended', 'new_permissions_accepted' => [
                'status' => IntegrationStatus::Active->value,
                'disabled_at' => null,
                'disconnected_at' => null,
                'last_synced_at' => now(),
            ],
            default => [],
        };

        if ($updates === []) {
            return;
        }

        $updates['updated_at'] = now();
        Integration::query()
            ->where('provider', IntegrationProvider::GitHub->value)
            ->where('external_id', $delivery->installation_id)
            ->update($updates);

        if ($action === 'deleted' || $action === 'suspend' || $action === 'suspended') {
            $integrationIds = Integration::query()
                ->where('provider', IntegrationProvider::GitHub->value)
                ->where('external_id', $delivery->installation_id)
                ->pluck('id');

            DB::table('ci_cd_pipelines')
                ->whereIn('integration_id', $integrationIds)
                ->update(['enabled' => false, 'updated_at' => now()]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function handleWorkflowRun(
        GitHubWebhookDelivery $delivery,
        array $payload,
        SyncCiCdRunStatus $sync,
    ): void {
        $workflowRun = is_array($payload['workflow_run'] ?? null) ? $payload['workflow_run'] : [];
        $runId = isset($workflowRun['id']) ? (string) $workflowRun['id'] : '';
        $repository = (string) data_get($payload, 'repository.full_name', '');

        if ($runId === '' || $repository === '' || $delivery->installation_id === null) {
            return;
        }

        $run = CiCdRun::query()
            ->with(['integration', 'pipeline', 'buildRecord'])
            ->where('external_run_id', $runId)
            ->whereHas('integration', fn ($query) => $query
                ->where('provider', IntegrationProvider::GitHub->value)
                ->where('external_id', $delivery->installation_id))
            ->whereHas('pipeline', fn ($query) => $query->where('repository_full_name', $repository))
            ->first();

        if (! $run || $run->reporting_mode !== BuildReportingMode::Automatic) {
            return;
        }

        $sync->handle($run, new ProviderRunStatus(
            runId: $runId,
            status: (string) ($workflowRun['status'] ?? ''),
            conclusion: isset($workflowRun['conclusion']) ? (string) $workflowRun['conclusion'] : null,
            htmlUrl: (string) ($workflowRun['html_url'] ?? $run->external_run_url ?? ''),
            startedAt: $this->date($workflowRun['run_started_at'] ?? null),
            completedAt: ($workflowRun['status'] ?? null) === 'completed'
                ? $this->date($workflowRun['updated_at'] ?? null)
                : null,
        ));

        if (($workflowRun['status'] ?? null) === 'completed') {
            SyncCiCdRunLogs::dispatch($run->id)->onQueue('integrations')->afterCommit();
        }
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
