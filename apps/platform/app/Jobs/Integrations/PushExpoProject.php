<?php

namespace App\Jobs\Integrations;

use App\Integrations\Expo\ExpoPushSynchronizer;
use App\Models\ExpoProject;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class PushExpoProject implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $expoProjectId) {}

    public function uniqueId(): string
    {
        return $this->expoProjectId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [15, 60, 300];
    }

    public function handle(ExpoPushSynchronizer $synchronizer): void
    {
        $project = ExpoProject::query()
            ->with(['integration', 'mobileApp'])
            ->find($this->expoProjectId);

        if (! $project || ! $project->auto_sync_enabled || ! $project->mobileApp) {
            return;
        }

        if (! $project->exists_on_expo || ! $project->integration->status->canDispatch()) {
            $project->update([
                'auto_sync_status' => 'failed',
                'auto_sync_error' => 'Reconnect the Expo account before automatic sync can continue.',
                'auto_sync_pending_at' => null,
            ]);

            return;
        }

        $project->update([
            'auto_sync_status' => 'syncing',
            'auto_sync_error' => null,
            'auto_sync_started_at' => now(),
        ]);

        try {
            $synchronizer->push($project);
        } catch (Throwable $exception) {
            $this->markFailed($exception);

            throw $exception;
        }

        $project->refresh()->update([
            'auto_sync_status' => 'synced',
            'auto_sync_error' => null,
            'auto_sync_pending_at' => null,
            'last_pushed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            $this->markFailed($exception);
        }
    }

    private function markFailed(Throwable $exception): void
    {
        ExpoProject::query()->whereKey($this->expoProjectId)->update([
            'auto_sync_status' => 'failed',
            'auto_sync_error' => Str::limit($exception->getMessage(), 2000, ''),
            'auto_sync_pending_at' => null,
        ]);
    }
}
