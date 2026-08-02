<?php

namespace App\Console\Commands;

use App\Enums\BuildStatus;
use App\Models\BuildRecord;
use App\Support\Builds\BuildLogArchive;
use Illuminate\Console\Command;
use Throwable;

class ArchiveBuildLogs extends Command
{
    protected $signature = 'build-logs:archive';

    protected $description = 'Move expired build logs from the database to private file storage.';

    public function handle(BuildLogArchive $archives): int
    {
        $days = (int) config('build-logs.retention_days', 30);

        if ($days < 1) {
            $this->error('BUILD_LOG_RETENTION_DAYS must be at least 1.');

            return self::FAILURE;
        }

        $archived = 0;
        $failed = 0;
        $cutoff = now()->subDays($days);

        BuildRecord::query()
            ->whereIn('status', [
                BuildStatus::Completed->value,
                BuildStatus::Failed->value,
                BuildStatus::Cancelled->value,
            ])
            ->whereNotNull('log_output')
            ->where('log_output', '<>', '')
            ->where(fn ($query) => $query
                ->where('logs_updated_at', '<', $cutoff)
                ->orWhere(fn ($legacy) => $legacy
                    ->whereNull('logs_updated_at')
                    ->where('created_at', '<', $cutoff)))
            ->lazyById(100)
            ->each(function (BuildRecord $build) use ($archives, &$archived, &$failed): void {
                try {
                    $archived += (int) $archives->archive($build);
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error("Failed to archive logs for build [{$build->id}].");
                }
            });

        $this->info("Archived {$archived} build log(s) older than {$days} day(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
