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
            'expo_build_url' => self::expoBuildUrl($app, $build),
            'parameters' => $build->parameters ?? [],
            'logs' => $build->log_output,
            'logs_archived_at' => $build->logs_archived_at,
            'log_archive_size' => $build->log_archive_size,
            'log_archive_url' => $build->log_archive_path
                ? route('accounts.apps.builds.logs.archive', [$team, $app, $build])
                : null,
            'log_download_url' => ($build->log_output !== null && $build->log_output !== '') || $build->log_archive_path
                ? route('accounts.apps.builds.logs.download', [$team, $app, $build])
                : null,
            'logs_pending' => $build->reporting_mode->value === 'automatic'
                && $run?->external_run_id !== null
                && $build->logs_updated_at === null
                && $build->logs_archived_at === null,
            'created_at' => $build->created_at,
            'tags' => $build->tags ?? [],
            'tags_url' => route('accounts.apps.builds.tags.update', [$team, $app, $build]),
            'started_at' => $build->started_at,
            'finished_at' => $build->finished_at,
            'show_url' => route('accounts.apps.builds.show', [$team, $app, $build]),
            'state_url' => route('accounts.apps.builds.state', [$team, $app, $build]),
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

    private static function expoBuildUrl(MobileApp $app, BuildRecord $build): ?string
    {
        if (! $build->expo_build_id) {
            return null;
        }

        $project = $app->expoProject;

        if (! $project || blank($project->account_name) || blank($project->slug)) {
            return null;
        }

        return rtrim((string) config('integrations.expo.web_url'), '/')
            .'/accounts/'.rawurlencode($project->account_name)
            .'/projects/'.rawurlencode($project->slug)
            .'/builds/'.rawurlencode($build->expo_build_id);
    }
}
