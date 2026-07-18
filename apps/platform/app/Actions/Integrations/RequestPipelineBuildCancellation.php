<?php

namespace App\Actions\Integrations;

use App\Enums\BuildStatus;
use App\Enums\CiCdDispatchStatus;
use App\Jobs\Integrations\CancelCiCdRun;
use App\Models\BuildRecord;
use App\Support\Builds\BuildLifecycle;

class RequestPipelineBuildCancellation
{
    public function __construct(private readonly BuildLifecycle $lifecycle) {}

    public function handle(BuildRecord $build): BuildRecord
    {
        $build = $this->lifecycle->requestCancellation($build);

        if ($build->status !== BuildStatus::CancelRequested) {
            return $build;
        }

        $run = $build->ciCdRun;

        if (! $run) {
            return $this->lifecycle->finish($build, BuildStatus::Cancelled);
        }

        if ($run->dispatch_status === CiCdDispatchStatus::Created) {
            $run->update(['dispatch_status' => CiCdDispatchStatus::Failed]);

            return $this->lifecycle->finish($build, BuildStatus::Cancelled);
        }

        if ($run->external_run_id !== null) {
            CancelCiCdRun::dispatch($run->id)->onQueue('integrations');
        }

        return $build->refresh();
    }
}
