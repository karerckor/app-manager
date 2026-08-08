<?php

namespace App\Actions\Integrations;

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\CiCdDispatchStatus;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Jobs\Integrations\CancelCiCdRun;
use App\Models\BuildRecord;
use App\Models\ExpoProject;
use App\Support\Builds\BuildLifecycle;

class RequestPipelineBuildCancellation
{
    public function __construct(
        private readonly BuildLifecycle $lifecycle,
        private readonly ExpoClient $expoClient,
    ) {}

    public function handle(BuildRecord $build): BuildRecord
    {
        $build = $this->lifecycle->requestCancellation($build);

        if ($build->status !== BuildStatus::CancelRequested) {
            return $build;
        }

        if ($build->reporting_mode === BuildReportingMode::Eas) {
            return $this->cancelEasBuild($build);
        }

        $run = $build->ciCdRun;

        if (! $run) {
            if ($build->reporting_mode === BuildReportingMode::Manual) {
                return $build;
            }

            return $this->lifecycle->finish($build, BuildStatus::Cancelled);
        }

        if ($run->dispatch_status === CiCdDispatchStatus::Created) {
            $run->update(['dispatch_status' => CiCdDispatchStatus::Failed]);

            return $this->lifecycle->finish($build, BuildStatus::Cancelled);
        }

        if ($run->external_run_id !== null) {
            CancelCiCdRun::dispatch($run->id);
        }

        return $build->refresh();
    }

    private function cancelEasBuild(BuildRecord $build): BuildRecord
    {
        if (! $build->expo_build_id) {
            return $this->lifecycle->finish($build, BuildStatus::Cancelled);
        }

        $expoProject = ExpoProject::query()
            ->where('mobile_app_id', $build->mobile_app_id)
            ->where('team_id', $build->team_id)
            ->with('integration')
            ->first();

        if (! $expoProject || ! $expoProject->integration) {
            return $this->lifecycle->finish($build, BuildStatus::Cancelled);
        }

        try {
            $this->expoClient->cancelBuild($expoProject->integration, $build->expo_build_id);
        } catch (ExpoGraphqlException) {
            return $this->lifecycle->finish($build, BuildStatus::Cancelled);
        }

        return $build->refresh();
    }
}
