<?php

namespace App\Support\Apps;

use App\Enums\BuildReportingMode;
use App\Models\BuildRecord;
use App\Models\MobileApp;
use App\Models\Team;

class BuildPresenter
{
    public static function present(Team $team, MobileApp $app, BuildRecord $build, bool $canRunPipelines, bool $canManageArtifacts): array
    {
        $run = $canRunPipelines ? $build->ciCdRun : null;

        return [
            'id' => $build->id,
            'status' => $build->status->value,
            'profile_type' => $build->profile_type->value,
            'pipeline_name' => $run?->pipeline?->name,
            'external_url' => $run?->external_run_url,
            'provider_status' => $run?->provider_status,
            'reporting_mode' => $build->reporting_mode->value,
            'parameters' => $build->parameters ?? [],
            'logs' => $build->log_output,
            'logs_pending' => $build->reporting_mode->value === 'automatic'
                && $run?->external_run_id !== null
                && $build->logs_updated_at === null,
            'created_at' => $build->created_at,
            'tags' => $build->tags ?? [],
            'tags_url' => route('accounts.apps.builds.tags.update', [$team, $app, $build]),
            'started_at' => $build->started_at,
            'finished_at' => $build->finished_at,
            'can_cancel' => $canRunPipelines && $build->status->canRequestCancellation(),
            'cancel_url' => route('accounts.apps.builds.cancel', [$team, $app, $build]),
            'can_force_status' => $canRunPipelines
                && $build->reporting_mode === BuildReportingMode::Manual
                && ! $build->status->isTerminal(),
            'force_status_url' => route('accounts.apps.builds.status.update', [$team, $app, $build]),
            'can_manage_artifacts' => $canManageArtifacts,
            'artifacts_url' => route('accounts.apps.builds.artifacts.store', [$team, $app, $build]),
            'artifacts' => $build->artifacts->map(function ($artifact) use ($team, $app, $build) {
                $media = $artifact->getFirstMedia('file');

                return [
                    'id' => $artifact->id,
                    'name' => $artifact->name,
                    'size' => $media?->size,
                    'mime' => $media?->mime_type,
                    'metadata' => $artifact->metadata,
                    'tags' => $artifact->tags ?? [],
                    'tags_url' => route('accounts.apps.builds.artifacts.tags.update', [$team, $app, $build, $artifact]),
                    'created_at' => $artifact->created_at,
                    'download_url' => route('accounts.apps.builds.artifacts.download', [$team, $app, $build, $artifact]),
                    'delete_url' => route('accounts.apps.builds.artifacts.destroy', [$team, $app, $build, $artifact]),
                ];
            })->values(),
        ];
    }
}
