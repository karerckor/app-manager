<?php

namespace App\Actions\Builds;

use App\Enums\BuildReportingMode;
use App\Jobs\Integrations\ReconcileEasBuild;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class AttachEasBuild
{
    public function __construct(private readonly FindEasBuild $findEasBuild) {}

    public function handle(
        Team $team,
        MobileApp $mobileApp,
        BuildRecord $build,
        string $expoBuildId,
    ): BuildRecord {
        abort_unless(
            $mobileApp->team_id === $team->id
            && $build->team_id === $team->id
            && $build->mobile_app_id === $mobileApp->id,
            404,
        );

        if ($build->reporting_mode === BuildReportingMode::Eas && $build->expo_build_id === $expoBuildId) {
            return $build;
        }

        abort_unless(
            $build->reporting_mode === BuildReportingMode::Manual,
            409,
            'Only a manually reported build can be switched to EAS reporting.',
        );

        $resolved = $this->findEasBuild->handle($team, $mobileApp, $expoBuildId);
        $expoProject = $resolved['project'];
        $easBuild = $resolved['build'];

        return DB::transaction(function () use ($build, $expoBuildId, $expoProject, $easBuild) {
            $current = BuildRecord::query()->lockForUpdate()->findOrFail($build->id);

            if ($current->reporting_mode === BuildReportingMode::Eas && $current->expo_build_id === $expoBuildId) {
                return $current;
            }

            abort_unless(
                $current->reporting_mode === BuildReportingMode::Manual,
                409,
                'Only a manually reported build can be switched to EAS reporting.',
            );

            $context = $current->context_snapshot ?? [];
            $context['eas'] = [
                'build_id' => $expoBuildId,
                'project_id' => $expoProject->external_id,
                'project_slug' => $expoProject->slug,
                'platform' => $easBuild['platform'] ?? null,
                'build_profile' => $easBuild['buildProfile'] ?? null,
                'preserved_manual_log_bytes' => strlen($current->log_output ?? ''),
            ];

            $current->update([
                'reporting_mode' => BuildReportingMode::Eas,
                'expo_build_id' => $expoBuildId,
                'context_snapshot' => $context,
            ]);

            DB::afterCommit(fn () => ReconcileEasBuild::dispatch($current->id));

            return $current->refresh();
        });
    }
}
