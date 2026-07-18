<?php

namespace App\Jobs\Integrations;

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\CiCdDispatchStatus;
use App\Integrations\CiCd\CiCdProviderRegistry;
use App\Models\CiCdRun;
use App\Support\Builds\BuildLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchCiCdRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $runId) {}

    public function handle(CiCdProviderRegistry $providers, BuildLifecycle $lifecycle): void
    {
        $shouldDispatch = DB::transaction(function () use ($lifecycle) {
            $run = CiCdRun::query()->with('buildRecord')->lockForUpdate()->findOrFail($this->runId);

            if ($run->dispatch_status === CiCdDispatchStatus::Accepted
                || $run->dispatch_status === CiCdDispatchStatus::Failed
                || $run->dispatch_status === CiCdDispatchStatus::Unknown) {
                return false;
            }

            if ($run->dispatch_status === CiCdDispatchStatus::Dispatching) {
                $run->update(['dispatch_status' => CiCdDispatchStatus::Unknown]);

                return false;
            }

            if ($run->buildRecord->status === BuildStatus::CancelRequested) {
                $run->update(['dispatch_status' => CiCdDispatchStatus::Failed]);
                $lifecycle->finish($run->buildRecord, BuildStatus::Cancelled);

                return false;
            }

            $run->update(['dispatch_status' => CiCdDispatchStatus::Dispatching]);

            return true;
        });

        if (! $shouldDispatch) {
            return;
        }

        $run = CiCdRun::query()->with(['integration', 'pipeline'])->findOrFail($this->runId);

        try {
            $snapshot = $run->request_snapshot;
            $result = $providers->for($run->integration->provider)->dispatch(
                $run->pipeline,
                $snapshot['ref'],
                $snapshot['inputs'],
            );
        } catch (Throwable $exception) {
            $definitiveFailure = $exception instanceof RequestException
                && $exception->response->clientError()
                && $exception->response->status() !== 429;
            $run->update([
                'dispatch_status' => $definitiveFailure
                    ? CiCdDispatchStatus::Failed
                    : CiCdDispatchStatus::Unknown,
            ]);

            if ($definitiveFailure) {
                $lifecycle->finish($run->buildRecord, BuildStatus::Failed);
            }

            report($exception);

            return;
        }

        $run->update([
            'dispatch_status' => CiCdDispatchStatus::Accepted,
            'external_run_id' => $result->runId,
            'external_run_url' => $result->htmlUrl,
            'dispatched_at' => now(),
        ]);

        if ($run->buildRecord()->where('status', BuildStatus::CancelRequested)->exists()) {
            CancelCiCdRun::dispatch($run->id)->onQueue('integrations');

            return;
        }

        if ($run->reporting_mode === BuildReportingMode::Automatic) {
            ReconcileCiCdRun::dispatch($run->id)
                ->delay(now()->addSeconds(5))
                ->onQueue('integrations');
        }
    }
}
