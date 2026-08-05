<?php

namespace App\Jobs\Sentry;

use App\Enums\IntegrationStatus;
use App\Enums\SentryEnvelopeStatus;
use App\Integrations\Sentry\SentryDsnUrl;
use App\Models\SentryEnvelope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

class ForwardSentryEnvelope implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $envelopeId) {}

    public function handle(SentryDsnUrl $dsnUrl): void
    {
        $envelope = SentryEnvelope::query()->with('sentryDsn.integration')->findOrFail($this->envelopeId);
        $dsn = $envelope->sentryDsn;

        if (in_array($envelope->status, [SentryEnvelopeStatus::Forwarded, SentryEnvelopeStatus::Exhausted], true)) {
            return;
        }

        if (! $dsn || $dsn->integration->status !== IntegrationStatus::Active || ! filled($dsn->upstream_dsn)) {
            $envelope->update([
                'status' => SentryEnvelopeStatus::Stored,
                'next_forward_at' => null,
            ]);

            return;
        }

        $envelope->increment('forward_attempts');
        $envelope->refresh();

        try {
            $upstream = $dsnUrl->parse($dsn->upstream_dsn, assertSafe: true);
            $body = $dsnUrl->replaceEnvelopeDsn($envelope->body(), $upstream['dsn']);
            $separator = str_contains($upstream['envelope_url'], '?') ? '&' : '?';
            $url = $upstream['envelope_url'].$separator.http_build_query([
                'sentry_key' => $upstream['public_key'],
                'sentry_version' => '7',
            ]);
            $response = Http::withHeaders([
                'Content-Type' => 'application/x-sentry-envelope',
                'User-Agent' => 'AppManager-Sentry-Relay/1.0',
            ])
                ->connectTimeout((int) config('integrations.sentry.connect_timeout_seconds', 3))
                ->timeout((int) config('integrations.sentry.timeout_seconds', 15))
                ->withoutRedirecting()
                ->withBody($body, 'application/x-sentry-envelope')
                ->post($url);

            if ($response->successful()) {
                $envelope->update([
                    'status' => SentryEnvelopeStatus::Forwarded,
                    'upstream_response_status' => $response->status(),
                    'forwarding_error' => null,
                    'next_forward_at' => null,
                    'forwarded_at' => now(),
                ]);
                $dsn->update([
                    'consecutive_forward_failures' => 0,
                    'last_forwarded_at' => now(),
                ]);

                return;
            }

            $this->retryOrExhaust($envelope, $dsn, 'Upstream Sentry returned HTTP '.$response->status().'.', $response->status());
        } catch (Throwable $exception) {
            $this->retryOrExhaust($envelope, $dsn, $exception->getMessage());
        }
    }

    private function retryOrExhaust(
        SentryEnvelope $envelope,
        $dsn,
        string $error,
        ?int $responseStatus = null,
    ): void {
        $dsn->increment('consecutive_forward_failures');
        $delays = config('integrations.sentry.retry_delays', [30, 300, 1800]);
        $delay = $delays[$envelope->forward_attempts - 1] ?? null;
        $status = $delay === null ? SentryEnvelopeStatus::Exhausted : SentryEnvelopeStatus::RetryScheduled;

        $envelope->update([
            'status' => $status,
            'upstream_response_status' => $responseStatus,
            'forwarding_error' => mb_substr($error, 0, 4096),
            'next_forward_at' => $delay === null ? null : now()->addSeconds($delay),
        ]);

        if ($delay !== null) {
            self::dispatch($envelope->id)->delay($delay)->onQueue('integrations');
        }
    }
}
