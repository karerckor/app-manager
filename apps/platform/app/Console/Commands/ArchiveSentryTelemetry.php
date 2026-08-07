<?php

namespace App\Console\Commands;

use App\Models\SentryEnvelope;
use App\Support\Sentry\SentryArchive;
use Illuminate\Console\Command;
use Throwable;

class ArchiveSentryTelemetry extends Command
{
    protected $signature = 'sentry:archive';

    protected $description = 'Move expired Sentry envelope bodies and event payloads to compressed private storage.';

    public function handle(SentryArchive $archives): int
    {
        $days = (int) config('sentry-retention.hot_retention_days', 30);

        if ($days < 1) {
            $this->error('SENTRY_HOT_RETENTION_DAYS must be at least 1.');

            return self::FAILURE;
        }

        $archived = 0;
        $failed = 0;

        SentryEnvelope::query()
            ->whereNull('archived_at')
            ->where('created_at', '<', now()->subDays($days))
            ->lazyById(100)
            ->each(function (SentryEnvelope $envelope) use ($archives, &$archived, &$failed): void {
                try {
                    $archived += (int) $archives->archive($envelope);
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->error("Failed to archive Sentry envelope [{$envelope->id}].");
                }
            });

        $this->info("Archived {$archived} Sentry envelope(s) older than {$days} day(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
