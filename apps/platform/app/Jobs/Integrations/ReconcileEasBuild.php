<?php

namespace App\Jobs\Integrations;

use App\Enums\BuildReportingMode;
use App\Enums\BuildStatus;
use App\Enums\WebhookEventType;
use App\Integrations\Expo\ExpoClient;
use App\Integrations\Expo\ExpoGraphqlException;
use App\Integrations\Webhooks\WebhookEventRecorder;
use App\Integrations\Webhooks\WebhookPayload;
use App\Models\BuildRecord;
use App\Models\ExpoProject;
use App\Models\Integration;
use App\Support\Builds\BuildLifecycle;
use App\Support\Builds\BuildLog;
use App\Support\Builds\BuildLogArchive;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileEasBuild implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const HANDOFF_LOG_HEADER = '--- EAS build logs ---';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 4;

    public function __construct(public readonly string $buildId, public readonly bool $notify = true) {}

    public function uniqueId(): string
    {
        return $this->buildId;
    }

    public function handle(ExpoClient $expoClient, BuildLifecycle $lifecycle): void
    {
        $build = BuildRecord::query()->with('mobileApp')->findOrFail($this->buildId);

        if ($build->reporting_mode !== BuildReportingMode::Eas || ! $build->expo_build_id) {
            return;
        }

        $expoProject = ExpoProject::query()
            ->where('mobile_app_id', $build->mobile_app_id)
            ->where('team_id', $build->team_id)
            ->with('integration')
            ->first();

        if (! $expoProject || ! $expoProject->integration) {
            Log::warning('EAS build reconciliation skipped: no Expo project linked', [
                'build_id' => $build->id,
                'mobile_app_id' => $build->mobile_app_id,
            ]);

            return;
        }

        $integration = $expoProject->integration;
        $easBuild = $this->findBuild($expoClient, $integration, $expoProject->external_id, $build->expo_build_id);

        if ($easBuild === null) {
            Log::warning('EAS build not found during reconciliation', [
                'build_id' => $build->id,
                'expo_build_id' => $build->expo_build_id,
            ]);

            return;
        }

        $status = (string) ($easBuild['status'] ?? '');
        $this->syncLogs($expoClient, $integration, $build, $easBuild);

        if ($build->status->isTerminal()) {
            // The build already finished locally; only backfill missing
            // logs and artifacts without replaying lifecycle transitions.
            $this->downloadArtifacts($expoClient, $integration, $build, $easBuild);

            return;
        }

        $terminalStatus = $this->mapStatus($status);
        $suppressEvents = ! $this->notify;
        $startedAt = $this->parseTime($easBuild['workerStartedAt'] ?? $easBuild['createdAt'] ?? null);
        $finishedAt = $this->parseTime($easBuild['completedAt'] ?? $easBuild['updatedAt'] ?? null);

        if ($terminalStatus === BuildStatus::Running && $build->status === BuildStatus::Pending) {
            $lifecycle->start($build, startedAt: $startedAt, suppressEvents: $suppressEvents);
        } elseif ($terminalStatus !== null && $terminalStatus->isTerminal()) {
            if ($build->status === BuildStatus::Pending) {
                $build = $lifecycle->start($build, startedAt: $startedAt, suppressEvents: $suppressEvents);
            }
            $lifecycle->finish($build, $terminalStatus, finishedAt: $finishedAt, suppressEvents: $suppressEvents);
            $this->downloadArtifacts($expoClient, $integration, $build, $easBuild);

            return;
        }

        self::dispatch($build->id, notify: $this->notify)
            ->delay(now()->addSeconds(10))
            ->onQueue('integrations');
    }

    /** @return array<string, mixed>|null */
    private function findBuild(
        ExpoClient $expoClient,
        Integration $integration,
        string $externalProjectId,
        string $expoBuildId,
    ): ?array {
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            try {
                $projectData = $expoClient->project(
                    $integration,
                    $externalProjectId,
                    buildLimit: self::PAGE_SIZE,
                    buildOffset: $page * self::PAGE_SIZE,
                );
            } catch (ExpoGraphqlException $exception) {
                Log::warning('EAS build fetch failed during reconciliation', [
                    'expo_build_id' => $expoBuildId,
                    'page' => $page,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $builds = (array) ($projectData['builds'] ?? []);

            if ($builds === []) {
                return null;
            }

            foreach ($builds as $candidate) {
                if (is_array($candidate) && ($candidate['id'] ?? '') === $expoBuildId) {
                    return $candidate;
                }
            }

            if (count($builds) < self::PAGE_SIZE) {
                return null;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $easBuild */
    private function syncLogs(
        ExpoClient $expoClient,
        Integration $integration,
        BuildRecord $build,
        array $easBuild,
    ): void {
        if ($build->logs_archived_at !== null) {
            return;
        }

        $logContent = '';

        try {
            $logContent = $expoClient->easBuildLogs($integration, $build->expo_build_id);
        } catch (\Throwable $exception) {
            Log::info('EAS build logs unavailable', [
                'build_id' => $build->id,
                'expo_build_id' => $build->expo_build_id,
                'error' => $exception->getMessage(),
            ]);
        }

        $isManualHandoff = $this->isManualHandoff($build);

        if ($logContent === '' && ($build->log_output === null || $isManualHandoff)) {
            $logContent = $this->buildSummary($easBuild);
        }

        if ($logContent === '') {
            return;
        }

        $combinedLogContent = $isManualHandoff
            ? $this->combineWithManualLogs($build, $logContent)
            : $logContent;

        $build->update([
            'log_output' => $combinedLogContent,
            'logs_updated_at' => now(),
        ]);

        // Inline logs beyond the manual-log ceiling make the build page
        // unrenderable; move them to the archive download instead.
        if (strlen($combinedLogContent) > BuildLog::MAX_TOTAL_BYTES) {
            app(BuildLogArchive::class)->archive($build);
            $build->refresh();
        }
    }

    private function isManualHandoff(BuildRecord $build): bool
    {
        $easContext = data_get($build->context_snapshot, 'eas');

        return is_array($easContext) && array_key_exists('preserved_manual_log_bytes', $easContext);
    }

    private function combineWithManualLogs(BuildRecord $build, string $easLogs): string
    {
        $preservedBytes = max(0, (int) data_get(
            $build->context_snapshot,
            'eas.preserved_manual_log_bytes',
            0,
        ));
        $manualLogs = substr((string) $build->log_output, 0, $preservedBytes);

        if ($manualLogs === '') {
            return $easLogs;
        }

        $separator = str_ends_with($manualLogs, "\n") ? "\n" : "\n\n";

        return $manualLogs.$separator.self::HANDOFF_LOG_HEADER."\n".$easLogs;
    }

    /** @param array<string, mixed> $easBuild */
    private function buildSummary(array $easBuild): string
    {
        $lines = [
            'EAS Build: '.(string) ($easBuild['id'] ?? 'unknown'),
            'Platform: '.(string) ($easBuild['platform'] ?? 'unknown'),
            'Status: '.(string) ($easBuild['status'] ?? 'unknown'),
            'Build Profile: '.(string) ($easBuild['buildProfile'] ?? 'unknown'),
            'App Version: '.(string) ($easBuild['appVersion'] ?? 'unknown'),
            'Build Version: '.(string) ($easBuild['appBuildVersion'] ?? 'unknown'),
        ];

        if (filled($easBuild['gitCommitHash'] ?? null)) {
            $lines[] = 'Git Commit: '.(string) $easBuild['gitCommitHash'];
        }

        if (is_array($easBuild['error'] ?? null)) {
            $errorCode = (string) ($easBuild['error']['errorCode'] ?? '');
            $errorMessage = (string) ($easBuild['error']['message'] ?? '');
            if ($errorCode !== '' || $errorMessage !== '') {
                $lines[] = '';
                $lines[] = 'Error: '.($errorCode !== '' ? "[{$errorCode}] " : '').$errorMessage;
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $easBuild */
    private function downloadArtifacts(
        ExpoClient $expoClient,
        Integration $integration,
        BuildRecord $build,
        array $easBuild,
    ): void {
        $artifacts = (array) ($easBuild['artifacts'] ?? []);
        $archiveUrl = (string) ($artifacts['applicationArchiveUrl'] ?? '');
        $buildUrl = (string) ($artifacts['buildUrl'] ?? '');

        Log::info('EAS artifact URLs from GraphQL', [
            'build_id' => $build->id,
            'expo_build_id' => $build->expo_build_id,
            'archive_url' => $archiveUrl,
            'build_url' => $buildUrl,
        ]);

        $urls = array_filter([
            'application_archive' => $archiveUrl,
            'build' => $buildUrl !== $archiveUrl ? $buildUrl : '',
        ]);

        foreach ($urls as $type => $url) {
            if ($url === '') {
                continue;
            }

            $build->artifacts()
                ->where('metadata->expo_artifact_type', $type)
                ->whereDoesntHave('media')
                ->get()
                ->each
                ->delete();

            $existing = $build->artifacts()
                ->where('metadata->expo_artifact_type', $type)
                ->exists();

            if ($existing) {
                Log::info('EAS artifact already attached, skipping', [
                    'build_id' => $build->id,
                    'type' => $type,
                ]);

                continue;
            }

            $this->downloadAndAttach($expoClient, $integration, $build, $url, $type, $easBuild);
        }
    }

    /** @param array<string, mixed> $easBuild */
    private function downloadAndAttach(
        ExpoClient $expoClient,
        Integration $integration,
        BuildRecord $build,
        string $url,
        string $type,
        array $easBuild,
    ): void {
        $platform = strtolower((string) ($easBuild['platform'] ?? 'unknown'));
        $extension = $this->guessExtension($url, $platform);
        $fileName = $build->expo_build_id.'-'.$type.$extension;
        $tempPath = storage_path('app/tmp/eas-artifacts/'.$fileName);

        try {
            $downloaded = $expoClient->downloadFile($integration, $url, $tempPath);

            if (! $downloaded || ! file_exists($tempPath)) {
                Log::warning('EAS artifact download failed', [
                    'build_id' => $build->id,
                    'url' => $url,
                ]);

                return;
            }

            $artifact = DB::transaction(function () use ($build, $fileName, $type, $platform, $url, $easBuild, $tempPath) {
                $artifact = $build->artifacts()->create([
                    'team_id' => $build->team_id,
                    'name' => $fileName,
                    'metadata' => [
                        'expo_artifact_type' => $type,
                        'platform' => $platform,
                        'source_url' => $url,
                        'app_version' => $easBuild['appVersion'] ?? null,
                        'build_version' => $easBuild['appBuildVersion'] ?? null,
                    ],
                ]);

                $artifact->addMedia($tempPath)
                    ->usingFileName($fileName)
                    ->toMediaCollection('file');

                return $artifact;
            });

            if ($this->notify) {
                app(WebhookEventRecorder::class)->record(
                    $build->team,
                    WebhookEventType::ArtifactCreated,
                    $artifact,
                    WebhookPayload::artifact($artifact),
                );
            }

            Log::info('EAS artifact attached successfully', [
                'build_id' => $build->id,
                'artifact_id' => $artifact->id,
                'type' => $type,
                'file' => $fileName,
            ]);
        } catch (\Throwable $exception) {
            Log::error('EAS artifact attachment failed', [
                'build_id' => $build->id,
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function guessExtension(string $url, string $platform): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $ext = is_string($path) ? pathinfo($path, PATHINFO_EXTENSION) : '';

        if ($ext !== '' && in_array(strtolower($ext), ['aab', 'apk', 'ipa', 'zip', 'tar', 'gz'], true)) {
            return '.'.$ext;
        }

        return match ($platform) {
            'ios' => '.ipa',
            'android' => '.aab',
            default => '.bin',
        };
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function mapStatus(string $easStatus): ?BuildStatus
    {
        return match (strtolower($easStatus)) {
            'new', 'in-queue', 'in_queue' => BuildStatus::Pending,
            'in-progress', 'in_progress' => BuildStatus::Running,
            'finished' => BuildStatus::Completed,
            'errored' => BuildStatus::Failed,
            'cancelled', 'canceled' => BuildStatus::Cancelled,
            default => null,
        };
    }
}
