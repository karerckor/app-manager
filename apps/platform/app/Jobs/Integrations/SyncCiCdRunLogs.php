<?php

namespace App\Jobs\Integrations;

use App\Enums\BuildReportingMode;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Models\CiCdRun;
use App\Support\Builds\BuildLog;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncCiCdRunLogs implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60];

    public function __construct(public readonly string $runId) {}

    public function uniqueId(): string
    {
        return $this->runId;
    }

    public function handle(CiCdProviderRegistry $providers, BuildLog $logs): void
    {
        $run = CiCdRun::query()->with(['integration', 'pipeline', 'buildRecord'])->findOrFail($this->runId);

        if ($run->reporting_mode !== BuildReportingMode::Automatic
            || $run->buildRecord->logs_updated_at !== null
            || $run->external_run_id === null) {
            return;
        }

        $output = $providers->for($run->integration->provider)->logs($run);
        $logs->replace($run->buildRecord, $output);

        $run->update([
            'log_output' => $output,
            'logs_synced_at' => now(),
        ]);
    }
}
