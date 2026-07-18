<?php

namespace App\Jobs\Integrations;

use App\Actions\Integrations\SyncCiCdRunStatus;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Models\CiCdRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileCiCdRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $runId) {}

    public function handle(CiCdProviderRegistry $providers, SyncCiCdRunStatus $sync): void
    {
        $run = CiCdRun::query()->with(['integration', 'pipeline', 'buildRecord'])->findOrFail($this->runId);

        if ($run->buildRecord->status->isTerminal() || $run->external_run_id === null) {
            return;
        }

        $status = $providers->for($run->integration->provider)->status($run);
        $sync->handle($run, $status);
    }
}
