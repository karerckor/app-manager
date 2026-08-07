<?php

namespace App\Support\Sentry;

use App\Models\SentryEvent;

class SentryTelemetryPresenter
{
    /** @return array<string, mixed> */
    public function event(SentryEvent $event, int $traceEventCount = 0, ?string $archiveUrl = null): array
    {
        $payload = $event->payload ?? [];

        return [
            'id' => $event->id,
            'event_id' => $event->event_id,
            'item_type' => $event->item_type,
            'level' => $event->level,
            'title' => $event->title ?: $event->transaction ?: 'Untitled event',
            'transaction' => $event->transaction,
            'trace_id' => $event->trace_id,
            'trace_event_count' => $traceEventCount,
            'release' => $event->release,
            'environment' => $event->environment,
            'platform' => $event->platform,
            'occurred_at' => $event->occurred_at,
            'identifier' => $event->appIdentifier?->identifier,
            'identifier_name' => $event->appIdentifier?->name,
            'identifier_platform' => $event->appIdentifier?->platform?->value,
            'dsn_name' => $event->sentryDsn?->integration?->name,
            'forward_status' => $event->envelope?->status?->value,
            'archive' => $event->envelope?->archived_at ? [
                'archived_at' => $event->envelope->archived_at,
                'size' => $event->envelope->archive_size,
                'checksum' => $event->envelope->archive_checksum,
                'url' => $archiveUrl,
            ] : null,
            'exceptions' => $this->exceptions($payload),
            'trace' => [
                'operation' => data_get($payload, 'contexts.trace.op'),
                'status' => data_get($payload, 'contexts.trace.status'),
                'duration_ms' => $this->durationMilliseconds($payload),
                'spans_count' => is_array($payload['spans'] ?? null) ? count($payload['spans']) : 0,
            ],
            'sdk' => array_filter(['name' => data_get($payload, 'sdk.name'), 'version' => data_get($payload, 'sdk.version')], fn ($value) => $value !== null && $value !== ''),
            'runtime' => array_filter(['name' => data_get($payload, 'contexts.runtime.name'), 'version' => data_get($payload, 'contexts.runtime.version')], fn ($value) => $value !== null && $value !== ''),
            'contexts' => $this->structuredData($payload['contexts'] ?? []),
            'user' => $this->structuredData($payload['user'] ?? []),
            'request' => $this->structuredData($payload['request'] ?? []),
            'tags' => $this->tags(data_get($payload, 'tags', [])),
            'extra' => $this->structuredData($payload['extra'] ?? []),
            'raw_payload' => $payload,
        ];
    }

    /** @return array<string, mixed> */
    public function occurrence(SentryEvent $event, string $url): array
    {
        return [
            'id' => $event->id, 'url' => $url, 'item_type' => $event->item_type,
            'title' => $event->title ?: $event->transaction ?: 'Untitled event',
            'level' => $event->level, 'environment' => $event->environment,
            'release' => $event->release, 'occurred_at' => $event->occurred_at,
            'trace_id' => $event->trace_id,
        ];
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function exceptions(array $payload): array
    {
        return collect(data_get($payload, 'exception.values', []))->filter(fn ($exception) => is_array($exception))->map(function (array $exception) {
            $frames = collect(data_get($exception, 'stacktrace.frames', []))->filter(fn ($frame) => is_array($frame))->reverse()->map(fn (array $frame) => [
                'filename' => $frame['filename'] ?? $frame['abs_path'] ?? null, 'function' => $frame['function'] ?? null,
                'module' => $frame['module'] ?? null, 'line' => $frame['lineno'] ?? null, 'column' => $frame['colno'] ?? null,
                'in_app' => (bool) ($frame['in_app'] ?? false), 'pre_context' => $this->sourceContext($frame['pre_context'] ?? []),
                'context_line' => is_scalar($frame['context_line'] ?? null) ? (string) $frame['context_line'] : null,
                'post_context' => $this->sourceContext($frame['post_context'] ?? []),
            ])->values()->all();

            return ['type' => $exception['type'] ?? null, 'value' => $exception['value'] ?? null, 'module' => $exception['module'] ?? null,
                'mechanism' => $this->structuredData($exception['mechanism'] ?? []), 'frames' => $frames, 'raw' => $exception];
        })->values()->all();
    }

    /** @return array<array-key, mixed> */
    private function structuredData(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array{key: string, value: string}> */
    private function tags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return collect($tags)->map(function ($value, $key) {
            if (is_array($value) && array_is_list($value) && count($value) >= 2) {
                return ['key' => (string) $value[0], 'value' => (string) $value[1]];
            }

            return ['key' => (string) $key, 'value' => is_scalar($value) ? (string) $value : json_encode($value)];
        })->values()->all();
    }

    /** @return list<string> */
    private function sourceContext(mixed $lines): array
    {
        return is_array($lines) ? collect($lines)->filter(fn ($line) => is_scalar($line) || $line === null)->map(fn ($line) => (string) $line)->take(20)->values()->all() : [];
    }

    /** @param array<string, mixed> $payload */
    private function durationMilliseconds(array $payload): ?int
    {
        $start = $this->timestamp(data_get($payload, 'start_timestamp'));
        $end = $this->timestamp(data_get($payload, 'timestamp'));

        return $start !== null && $end !== null && $end >= $start ? (int) round(($end - $start) * 1000) : null;
    }

    private function timestamp(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        } if (! is_string($value) || trim($value) === '') {
            return null;
        } $timestamp = strtotime($value);

        return $timestamp === false ? null : (float) $timestamp;
    }
}
