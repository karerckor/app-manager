<?php

namespace App\Console\Commands;

use App\Models\BuildRecord;
use App\Support\Builds\BuildLogArchive;
use Illuminate\Console\Command;
use Throwable;

class PruneBuildLogArchives extends Command
{
    protected $signature = 'build-logs:prune-archives';

    protected $description = 'Delete build log archive objects after the configured cold retention period.';

    public function handle(BuildLogArchive $archives): int
    {
        $days = (int) config('build-logs.archive_retention_days', 365);

        if ($days < 1) {
            $this->error('BUILD_LOG_ARCHIVE_RETENTION_DAYS must be at least 1.');

            return self::FAILURE;
        }

        $purged = 0;
        $failed = 0;

        BuildRecord::withTrashed()
            ->whereNotNull('log_archive_path')
            ->where('logs_archived_at', '<', now()->subDays($days))
            ->lazyById(100)
            ->each(function (BuildRecord $build) use ($archives, &$purged, &$failed): void {
                try {
                    $purged += (int) $archives->purge($build);
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error("Failed to purge build log archive [{$build->id}].");
                }
            });

        $this->info("Purged {$purged} build log archive(s) older than {$days} day(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
