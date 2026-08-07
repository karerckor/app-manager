<?php

namespace App\Support\Sentry;

use App\Enums\SentryEnvelopeStatus;
use App\Models\SentryEnvelope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SentryArchive
{
    /** @throws JsonException */
    public function archive(SentryEnvelope $envelope): bool
    {
        $disk = $this->disk();
        $path = null;

        try {
            return DB::transaction(function () use ($envelope, $disk, &$path): bool {
                $locked = SentryEnvelope::query()
                    ->with('events')
                    ->lockForUpdate()
                    ->findOrFail($envelope->id);

                if ($locked->archived_at !== null) {
                    return false;
                }

                $document = [
                    'format' => 'app-manager-sentry-envelope-v1',
                    'archived_at' => now()->toIso8601String(),
                    'envelope' => Arr::except($locked->attributesToArray(), [
                        'archive_disk', 'archive_path', 'archive_size',
                        'archive_checksum', 'archived_at',
                    ]),
                    'events' => $locked->events->map(fn ($event) => $event->attributesToArray())->values()->all(),
                ];
                $json = json_encode(
                    $document,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
                $compressed = gzencode($json, 6);

                if ($compressed === false) {
                    throw new RuntimeException("Could not compress Sentry envelope [{$locked->id}].");
                }

                $path = $this->path($locked);

                if (! Storage::disk($disk)->put($path, $compressed, [
                    'visibility' => 'private',
                    'ContentType' => 'application/gzip',
                ])) {
                    throw new RuntimeException("Could not archive Sentry envelope [{$locked->id}].");
                }

                $locked->events()->update(['payload' => null]);
                $updates = [
                    'body_base64' => null,
                    'archive_disk' => $disk,
                    'archive_path' => $path,
                    'archive_size' => strlen($compressed),
                    'archive_checksum' => hash('sha256', $compressed),
                    'archived_at' => now(),
                ];

                if (in_array($locked->status, [
                    SentryEnvelopeStatus::Pending,
                    SentryEnvelopeStatus::RetryScheduled,
                ], true)) {
                    $updates += [
                        'status' => SentryEnvelopeStatus::Exhausted,
                        'forwarding_error' => $locked->forwarding_error
                            ?: 'Forwarding stopped when the hot retention window expired.',
                        'next_forward_at' => null,
                    ];
                }

                $locked->update($updates);

                return true;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }
    }

    public function download(SentryEnvelope $envelope): StreamedResponse
    {
        abort_unless($envelope->archive_disk && $envelope->archive_path, 404);
        $storage = Storage::disk($envelope->archive_disk);
        abort_unless($storage->exists($envelope->archive_path), 404);

        return $storage->download(
            $envelope->archive_path,
            "sentry-envelope-{$envelope->id}.json.gz",
            [
                'Content-Type' => 'application/gzip',
                'Content-Length' => (string) $envelope->archive_size,
                'X-Content-SHA256' => (string) $envelope->archive_checksum,
            ],
        );
    }

    public function purge(SentryEnvelope $envelope): void
    {
        DB::transaction(function () use ($envelope): void {
            $locked = SentryEnvelope::query()->lockForUpdate()->findOrFail($envelope->id);
            $locked->delete();
        });
    }

    private function disk(): string
    {
        return (string) (config('sentry-retention.disk') ?: config('media-library.disk_name', 'media-local'));
    }

    private function path(SentryEnvelope $envelope): string
    {
        $date = ($envelope->created_at ?? now())->format('Y/m');

        return "sentry-archives/{$envelope->team_id}/{$envelope->mobile_app_id}/{$date}/{$envelope->id}.json.gz";
    }
}
