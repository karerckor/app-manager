<?php

namespace App\Integrations\Sentry;

use App\Enums\SentryEnvelopeStatus;
use App\Models\SentryDsn;
use App\Models\SentryDsnRoute;
use App\Models\SentryEnvelope;
use App\Models\SentryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SentryEventRecorder
{
    public function record(
        SentryDsn $dsn,
        SentryDsnRoute $route,
        ParsedEnvelope $parsed,
        string $body,
    ): SentryEnvelope {
        return DB::transaction(function () use ($dsn, $route, $parsed, $body) {
            $hasUpstream = filled($dsn->upstream_dsn);
            $envelope = SentryEnvelope::create([
                'team_id' => $dsn->team_id,
                'sentry_dsn_id' => $dsn->id,
                'mobile_app_id' => $route->mobile_app_id,
                'app_identifier_id' => $route->app_identifier_id,
                'header' => $parsed->header,
                'body_base64' => base64_encode($body),
                'status' => $hasUpstream ? SentryEnvelopeStatus::Pending : SentryEnvelopeStatus::Stored,
            ]);

            foreach ($parsed->items as $item) {
                $type = (string) ($item['header']['type'] ?? '');
                $payload = $item['json'];

                if (! in_array($type, ['event', 'transaction', 'profile'], true) || ! is_array($payload)) {
                    continue;
                }

                SentryEvent::create([
                    'team_id' => $dsn->team_id,
                    'sentry_dsn_id' => $dsn->id,
                    'sentry_envelope_id' => $envelope->id,
                    'mobile_app_id' => $route->mobile_app_id,
                    'app_identifier_id' => $route->app_identifier_id,
                    'event_id' => $payload['event_id'] ?? $parsed->header['event_id'] ?? null,
                    'item_type' => $type,
                    'level' => $payload['level'] ?? ($type === 'transaction' ? 'info' : null),
                    'title' => $this->title($payload, $type),
                    'transaction' => $payload['transaction'] ?? null,
                    'release' => $payload['release'] ?? null,
                    'environment' => $payload['environment'] ?? null,
                    'platform' => $payload['platform'] ?? null,
                    'trace_id' => data_get($payload, 'contexts.trace.trace_id')
                        ?? data_get($payload, 'trace.trace_id'),
                    'occurred_at' => $this->timestamp($payload['timestamp'] ?? null),
                    'payload' => $payload,
                ]);
            }

            $dsn->update(['last_event_at' => now()]);

            return $envelope;
        });
    }

    /** @param array<string, mixed> $payload */
    private function title(array $payload, string $type): ?string
    {
        if ($type === 'transaction') {
            return $payload['transaction'] ?? 'Transaction';
        }

        $exception = data_get($payload, 'exception.values.0');

        if (is_array($exception)) {
            return trim(implode(': ', array_filter([
                $exception['type'] ?? null,
                $exception['value'] ?? null,
            ])));
        }

        $message = $payload['message'] ?? null;

        return is_array($message) ? ($message['formatted'] ?? $message['message'] ?? null) : $message;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return now()->toImmutable();
        }

        try {
            return is_numeric($value)
                ? CarbonImmutable::createFromTimestampUTC((float) $value)
                : CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return now()->toImmutable();
        }
    }
}
