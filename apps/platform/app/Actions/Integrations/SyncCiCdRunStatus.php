<?php

namespace App\Actions\Integrations;

use App\Enums\BuildStatus;
use App\Integrations\CiCd\Data\ProviderRunStatus;
use App\Models\CiCdRun;
use App\Support\Builds\BuildLifecycle;
use Illuminate\Support\Facades\DB;

class SyncCiCdRunStatus
{
    public function __construct(private readonly BuildLifecycle $lifecycle) {}

    public function handle(CiCdRun $run, ProviderRunStatus $status): CiCdRun
    {
        return DB::transaction(function () use ($run, $status) {
            $run = CiCdRun::query()
                ->with('buildRecord')
                ->lockForUpdate()
                ->findOrFail($run->id);
            $run->update([
                'external_run_url' => $status->htmlUrl ?: $run->external_run_url,
                'provider_status' => $status->status,
                'provider_conclusion' => $status->conclusion,
                'last_synced_at' => now(),
            ]);
            $build = $run->buildRecord->refresh();

            if ($build->status->isTerminal()) {
                return $run->refresh();
            }

            if ($status->status === 'in_progress') {
                $this->lifecycle->start($build);
            } elseif ($status->status === 'completed') {
                $terminal = match ($status->conclusion) {
                    'success', 'neutral' => BuildStatus::Completed,
                    'cancelled' => BuildStatus::Cancelled,
                    default => BuildStatus::Failed,
                };
                $this->lifecycle->finish($build, $terminal);
            }

            return $run->refresh();
        });
    }
}
