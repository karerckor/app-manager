<?php

namespace App\Integrations\Sentry;

use App\Models\SentryEvent;
use App\Models\SentryIssue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Groups repeat error events while preserving every original occurrence. */
class SentryIssueGrouper
{
    private const GROUPING_VERSION = 'v1';

    public function assign(SentryEvent $event): void
    {
        // Transactions belong to the trace explorer, not the error issue inbox.
        if ($event->item_type !== 'event' || $event->sentry_issue_id !== null) {
            return;
        }

        $fingerprint = $this->fingerprint($event);
        $scopeKey = $event->app_identifier_id ?: 'application';
        $occurredAt = $event->occurred_at?->toImmutable() ?? now()->toImmutable();

        DB::transaction(function () use ($event, $fingerprint, $scopeKey, $occurredAt) {
            $issue = $this->findIssue($event, $scopeKey, $fingerprint);

            if (! $issue) {
                // ON CONFLICT DO NOTHING keeps a duplicate fingerprint from aborting
                // this transaction. The row is locked immediately afterwards before
                // its counters and timestamps are changed.
                DB::table('sentry_issues')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'team_id' => $event->team_id,
                    'mobile_app_id' => $event->mobile_app_id,
                    'app_identifier_id' => $event->app_identifier_id,
                    'scope_key' => $scopeKey,
                    'fingerprint' => $fingerprint,
                    'grouping_version' => self::GROUPING_VERSION,
                    'status' => 'unresolved',
                    'level' => $event->level,
                    'title' => $event->title ?: 'Untitled event',
                    'events_count' => 0,
                    'first_seen_at' => $occurredAt,
                    'last_seen_at' => $occurredAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $issue = $this->findIssue($event, $scopeKey, $fingerprint);

                if (! $issue) {
                    throw new \RuntimeException('The Sentry issue could not be grouped.');
                }
            }

            $issue->forceFill([
                'events_count' => $issue->events_count + 1,
                'first_seen_at' => $issue->first_seen_at->lessThanOrEqualTo($occurredAt)
                    ? $issue->first_seen_at
                    : $occurredAt,
                'last_seen_at' => $issue->last_seen_at->greaterThanOrEqualTo($occurredAt)
                    ? $issue->last_seen_at
                    : $occurredAt,
                'level' => $event->level ?: $issue->level,
                'title' => $event->title ?: $issue->title,
            ])->save();

            $event->forceFill(['sentry_issue_id' => $issue->id])->saveQuietly();
        });
    }

    private function findIssue(SentryEvent $event, string $scopeKey, string $fingerprint): ?SentryIssue
    {
        return SentryIssue::query()
            ->where('mobile_app_id', $event->mobile_app_id)
            ->where('scope_key', $scopeKey)
            ->where('fingerprint', $fingerprint)
            ->lockForUpdate()
            ->first();
    }

    private function fingerprint(SentryEvent $event): string
    {
        $payload = $event->payload ?? [];
        $custom = $payload['fingerprint'] ?? null;
        $default = $this->defaultFingerprintParts($event, $payload);

        if (is_array($custom) && $custom !== []) {
            $parts = ['custom'];

            foreach ($custom as $part) {
                if (! is_scalar($part)) {
                    continue;
                }

                $parts[] = (string) $part === '{{ default }}'
                    ? $default
                    : (string) $part;
            }

            if (count($parts) > 1) {
                return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
            }
        }

        return hash('sha256', json_encode($default, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload
     * @return list<string>
     */
    private function defaultFingerprintParts(SentryEvent $event, array $payload): array
    {
        $exception = data_get($payload, 'exception.values.0');

        if (is_array($exception)) {
            $frame = collect(data_get($exception, 'stacktrace.frames', []))
                ->filter(fn ($candidate) => is_array($candidate) && ($candidate['in_app'] ?? false))
                ->last();

            return [
                'exception',
                $this->normalise($exception['type'] ?? 'Error'),
                $this->normalise($exception['value'] ?? $event->title ?? ''),
                $this->normalise(is_array($frame) ? ($frame['filename'] ?? $frame['abs_path'] ?? '') : ''),
                $this->normalise(is_array($frame) ? ($frame['function'] ?? '') : ''),
            ];
        }

        $message = $payload['message'] ?? $event->title ?? '';
        $message = is_array($message) ? ($message['formatted'] ?? $message['message'] ?? '') : $message;

        return [
            'message',
            $this->normalise($payload['logger'] ?? ''),
            $this->normalise($event->level ?? ''),
            $this->normalise($message),
        ];
    }

    private function normalise(mixed $value): string
    {
        $value = Str::lower(trim(is_scalar($value) ? (string) $value : ''));
        $value = preg_replace('/\b[0-9a-f]{8}-[0-9a-f-]{27,}\b/i', ':id', $value) ?? $value;
        $value = preg_replace('/\b\d+\b/', ':number', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }
}
