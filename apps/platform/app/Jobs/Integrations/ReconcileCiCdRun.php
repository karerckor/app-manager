<?php

namespace App\Jobs\Integrations;

use App\Actions\Integrations\SyncCiCdRunStatus;
use App\Enums\BuildReportingMode;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Models\CiCdRun;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileCiCdRun implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $runId) {}

    public function uniqueId(): string
    {
        return $this->runId;
    }

    public function handle(CiCdProviderRegistry $providers, SyncCiCdRunStatus $sync): void
    {
        $run = CiCdRun::query()->with(['integration', 'pipeline', 'buildRecord'])->findOrFail($this->runId);

        if ($run->reporting_mode !== BuildReportingMode::Automatic || $run->external_run_id === null) {
            return;
        }

        if ($run->buildRecord->status->isTerminal()) {
            if ($run->buildRecord->logs_updated_at === null) {
                SyncCiCdRunLogs::dispatch($run->id);
            }

            return;
        }

        $status = $providers->for($run->integration->provider)->status($run);
        $sync->handle($run, $status);

        if ($status->status === 'completed') {
            SyncCiCdRunLogs::dispatch($run->id);

            return;
        }

        self::dispatch($run->id)->delay(now()->addSeconds(10));
    }
}
