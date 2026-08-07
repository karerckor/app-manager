<?php

namespace App\Http\Controllers\Sentry;

use App\Enums\IntegrationStatus;
use App\Http\Controllers\Controller;
use App\Integrations\Sentry\SentryDsnUrl;
use App\Integrations\Sentry\SentryEnvelopeParser;
use App\Integrations\Sentry\SentryEventRecorder;
use App\Integrations\Sentry\SentryRouteResolver;
use App\Integrations\Sentry\SentryRoutingException;
use App\Jobs\Sentry\ForwardSentryEnvelope;
use App\Models\SentryDsn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Throwable;

class SentryEnvelopeController extends Controller
{
    public function __construct(
        private readonly SentryEnvelopeParser $parser,
        private readonly SentryRouteResolver $resolver,
        private readonly SentryEventRecorder $recorder,
        private readonly SentryDsnUrl $dsnUrl,
    ) {}

    public function store(Request $request, string $sentryProjectId): JsonResponse
    {
        if (! config('integrations.enabled') || ! config('integrations.sentry.enabled')) {
            return $this->json(['detail' => 'Sentry ingestion is disabled.'], 404);
        }

        $dsn = SentryDsn::query()
            ->with('integration')
            ->whereHas('team')
            ->where('project_id', $sentryProjectId)
            ->first();

        if (! $dsn || $dsn->integration->status !== IntegrationStatus::Active) {
            return $this->json(['detail' => 'Unknown Sentry project.'], 404);
        }

        $body = $request->getContent();
        $maxBytes = (int) config('integrations.sentry.max_envelope_bytes', 10 * 1024 * 1024);

        if (strlen($body) > $maxBytes) {
            return $this->json(['detail' => 'The Sentry envelope is too large.'], 413);
        }

        $encoding = strtolower(trim((string) $request->header('Content-Encoding')));
        if ($encoding === 'gzip') {
            $decoded = gzdecode($body);
            if ($decoded === false) {
                return $this->json(['detail' => 'The gzip envelope body is invalid.'], 400);
            }
            $body = $decoded;
        } elseif ($encoding !== '' && $encoding !== 'identity') {
            return $this->json(['detail' => 'Unsupported Sentry envelope encoding.'], 415);
        }

        if (strlen($body) > $maxBytes) {
            return $this->json(['detail' => 'The uncompressed Sentry envelope is too large.'], 413);
        }

        try {
            $parsed = $this->parser->parse($body);
        } catch (InvalidArgumentException $exception) {
            return $this->json(['detail' => $exception->getMessage()], 400);
        }

        $publicKey = $this->acceptedPublicKey($request, $parsed->header, $dsn);

        if ($publicKey === null) {
            return $this->json(['detail' => 'Invalid Sentry public key.'], 401);
        }

        try {
            $directRoute = $publicKey === $dsn->public_key
                ? null
                : $dsn->routes()->with(['mobileApp', 'appIdentifier'])->where('public_key', $publicKey)->first();
            if ($directRoute && ($directRoute->mobileApp === null
                || ($directRoute->app_identifier_id !== null && $directRoute->appIdentifier === null))) {
                throw new SentryRoutingException('This Sentry target is archived.');
            }
            $route = $directRoute ?? $this->resolver->resolve($dsn, $request, $parsed->routingPayload());
        } catch (SentryRoutingException $exception) {
            return $this->json(['detail' => $exception->getMessage()], 400);
        }

        try {
            $envelope = $this->recorder->record($dsn, $route, $parsed, $body);
        } catch (Throwable $exception) {
            report($exception);

            return $this->json(['detail' => 'The Sentry envelope could not be stored.'], 500);
        }

        if (filled($dsn->upstream_dsn)) {
            ForwardSentryEnvelope::dispatch($envelope->id)->onQueue('integrations')->afterCommit();
        }

        return $this->json([
            'id' => $parsed->header['event_id'] ?? $envelope->events()->value('event_id'),
        ]);
    }

    public function options(): Response
    {
        return response('', 204)->withHeaders($this->corsHeaders());
    }

    /** @param array<string, mixed> $header */
    private function acceptedPublicKey(Request $request, array $header, SentryDsn $dsn): ?string
    {
        $candidates = [
            $request->query('sentry_key'),
            $this->keyFromAuthHeader((string) $request->header('X-Sentry-Auth')),
            $this->keyFromDsn((string) ($header['dsn'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            if (strlen($candidate) === strlen($dsn->public_key)
                && hash_equals($dsn->public_key, $candidate)) {
                return $dsn->public_key;
            }

            if ($dsn->routes()->where('public_key', $candidate)->exists()) {
                return $candidate;
            }
        }

        return null;
    }

    private function keyFromAuthHeader(string $header): ?string
    {
        return preg_match('/(?:^|[,\s])sentry_key=([^,\s]+)/', $header, $matches)
            ? $matches[1]
            : null;
    }

    private function keyFromDsn(string $dsn): ?string
    {
        try {
            return $this->dsnUrl->parse($dsn)['public_key'];
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->withHeaders($this->corsHeaders());
    }

    /** @return array<string, string> */
    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Content-Encoding, X-Sentry-Auth, X-Requested-With, *',
            'Access-Control-Max-Age' => '86400',
        ];
    }
}
