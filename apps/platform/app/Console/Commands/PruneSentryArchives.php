<?php

namespace App\Console\Commands;

use App\Models\SentryEnvelope;
use App\Support\Sentry\SentryArchive;
use Illuminate\Console\Command;
use Throwable;

class PruneSentryArchives extends Command
{
    protected $signature = 'sentry:prune-archives';

    protected $description = 'Permanently delete Sentry cold archives after the configured retention period.';

    public function handle(SentryArchive $archives): int
    {
        $days = (int) config('sentry-retention.archive_retention_days', 365);

        if ($days < 1) {
            $this->error('SENTRY_ARCHIVE_RETENTION_DAYS must be at least 1.');

            return self::FAILURE;
        }

        $purged = 0;
        $failed = 0;

        SentryEnvelope::query()
            ->whereNotNull('archived_at')
            ->where('archived_at', '<', now()->subDays($days))
            ->lazyById(100)
            ->each(function (SentryEnvelope $envelope) use ($archives, &$purged, &$failed): void {
                try {
                    $archives->purge($envelope);
                    $purged++;
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error("Failed to purge Sentry envelope [{$envelope->id}].");
                }
            });

        $this->info("Purged {$purged} Sentry archive(s) older than {$days} day(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
