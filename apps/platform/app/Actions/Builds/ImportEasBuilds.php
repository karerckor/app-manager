<?php

namespace App\Actions\Builds;

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\ProfileType;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Jobs\Integrations\ReconcileEasBuild;
use App\Models\BuildRecord;
use App\Models\ExpoProject;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportEasBuilds
{
    private const FETCH_LIMIT = 50;

    public function __construct(
        private readonly ExpoClient $expoClient,
    ) {}

    /**
     * @param  list<string>  $expoBuildIds
     * @return list<BuildRecord>
     */
    public function handle(
        Team $team,
        MobileApp $mobileApp,
        User $user,
        array $expoBuildIds,
    ): array {
        abort_unless($mobileApp->team_id === $team->id, 404);

        $expoProject = ExpoProject::query()
            ->where('mobile_app_id', $mobileApp->id)
            ->where('team_id', $team->id)
            ->with('integration')
            ->first();

        if (! $expoProject) {
            throw ValidationException::withMessages([
                'expo' => 'This application is not connected to EAS. Please link an Expo project first.',
            ]);
        }

        if (! $expoProject->exists_on_expo) {
            throw ValidationException::withMessages([
                'expo' => 'This Expo project appears to be detached. The project no longer exists on expo.dev.',
            ]);
        }

        $integration = $expoProject->integration;

        if (! $integration->status->canDispatch()) {
            throw ValidationException::withMessages([
                'expo' => 'The Expo integration is not active. Please reconnect your Expo account.',
            ]);
        }

        $existingIds = BuildRecord::query()
            ->where('team_id', $team->id)
            ->where('mobile_app_id', $mobileApp->id)
            ->whereIn('expo_build_id', $expoBuildIds)
            ->pluck('expo_build_id')
            ->all();

        $newIds = array_values(array_diff($expoBuildIds, $existingIds));

        if ($newIds === []) {
            throw ValidationException::withMessages([
                'builds' => 'All selected builds have already been imported.',
            ]);
        }

        try {
            $projectData = $this->expoClient->project(
                $integration,
                $expoProject->external_id,
                buildLimit: self::FETCH_LIMIT,
            );
        } catch (ExpoGraphqlException $exception) {
            throw ValidationException::withMessages([
                'builds' => 'Unable to fetch builds from Expo: '.$exception->getMessage(),
            ]);
        }

        $remoteBuilds = collect((array) ($projectData['builds'] ?? []))
            ->keyBy(fn ($build) => (string) ($build['id'] ?? ''));

        $notFound = [];
        foreach ($newIds as $buildId) {
            if (! $remoteBuilds->has($buildId)) {
                $notFound[] = $buildId;
            }
        }

        if ($notFound !== []) {
            throw ValidationException::withMessages([
                'builds' => count($notFound) === 1
                    ? "EAS build {$notFound[0]} was not found in the recent builds. It may be too old or belong to a different project."
                    : count($notFound).' selected builds were not found in the recent builds. They may be too old or belong to a different project.',
            ]);
        }

        $imported = [];

        foreach ($newIds as $buildId) {
            $easBuild = $remoteBuilds->get($buildId);
            $profileType = $this->resolveProfileType($easBuild);

            try {
                $build = DB::transaction(function () use (
                    $team,
                    $mobileApp,
                    $user,
                    $buildId,
                    $profileType,
                    $easBuild,
                    $expoProject,
                ) {
                    return BuildRecord::create([
                        'team_id' => $team->id,
                        'mobile_app_id' => $mobileApp->id,
                        'requested_by_user_id' => $user->id,
                        'request_uuid' => (string) Str::uuid(),
                        'profile_type' => $profileType,
                        'status' => BuildStatus::Pending,
                        'reporting_mode' => BuildReportingMode::Eas,
                        'expo_build_id' => $buildId,
                        'context_snapshot' => [
                            'schema_version' => 1,
                            'source' => 'web',
                            'app' => [
                                'id' => $mobileApp->id,
                                'slug' => $mobileApp->slug,
                                'name' => $mobileApp->name,
                            ],
                            'target' => null,
                            'profile_type' => $profileType->value,
                            'eas' => [
                                'build_id' => $buildId,
                                'project_id' => $expoProject->external_id,
                                'project_slug' => $expoProject->slug,
                                'platform' => $easBuild['platform'] ?? null,
                                'build_profile' => $easBuild['buildProfile'] ?? null,
                                'distribution' => $easBuild['distribution'] ?? null,
                            ],
                        ],
                    ]);
                });
            } catch (Throwable $exception) {
                Log::error('EAS build import failed', [
                    'build_id' => $buildId,
                    'mobile_app_id' => $mobileApp->id,
                    'error' => $exception->getMessage(),
                ]);

                throw ValidationException::withMessages([
                    'builds' => "Failed to import build {$buildId}: ".$exception->getMessage(),
                ]);
            }

            ReconcileEasBuild::dispatch($build->id, notify: false)
                ->delay(now()->addSeconds(count($imported) * 2))
                ->onQueue('integrations');

            $imported[] = $build;
        }

        return $imported;
    }

    /** @param array<string, mixed> $easBuild */
    private function resolveProfileType(array $easBuild): ProfileType
    {
        $platform = strtoupper((string) ($easBuild['platform'] ?? ''));
        $distribution = strtoupper((string) ($easBuild['distribution'] ?? ''));
        $buildProfile = strtolower((string) ($easBuild['buildProfile'] ?? ''));

        if ($platform === 'IOS') {
            return match (true) {
                $distribution === 'STORE' => ProfileType::AppStore,
                $distribution === 'INTERNAL' => ProfileType::AdHoc,
                str_contains($buildProfile, 'production') => ProfileType::AppStore,
                str_contains($buildProfile, 'enterprise') => ProfileType::Enterprise,
                str_contains($buildProfile, 'adhoc') || str_contains($buildProfile, 'ad-hoc') => ProfileType::AdHoc,
                default => ProfileType::Development,
            };
        }

        return match (true) {
            $distribution === 'STORE' => ProfileType::Production,
            $distribution === 'INTERNAL' => ProfileType::Preview,
            str_contains($buildProfile, 'production') => ProfileType::Production,
            str_contains($buildProfile, 'preview') => ProfileType::Preview,
            default => ProfileType::Development,
        };
    }
}
