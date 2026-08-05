<?php

namespace App\Integrations\Sentry;

use App\Integrations\Webhooks\WebhookUrlGuard;
use InvalidArgumentException;

class SentryDsnUrl
{
    public function __construct(private readonly WebhookUrlGuard $urlGuard) {}

    /** @return array{dsn: string, public_key: string, envelope_url: string} */
    public function parse(string $dsn, bool $assertSafe = false): array
    {
        if (! filter_var($dsn, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Enter a valid Sentry DSN.');
        }

        $parts = parse_url($dsn);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $publicKey = (string) ($parts['user'] ?? '');
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $projectId = (string) array_pop($segments);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $publicKey === '' || $projectId === '') {
            throw new InvalidArgumentException('The Sentry DSN must include a scheme, public key, host, and project ID.');
        }

        if (app()->environment('production') && $scheme !== 'https') {
            throw new InvalidArgumentException('Sentry DSNs must use HTTPS in production.');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $prefix = $segments === [] ? '' : '/'.implode('/', $segments);
        $endpoint = "{$scheme}://{$host}{$port}{$prefix}/api/{$projectId}/envelope/";

        if ($assertSafe) {
            $this->urlGuard->assertSafe($endpoint);
        }

        return [
            'dsn' => $dsn,
            'public_key' => $publicKey,
            'envelope_url' => $endpoint,
        ];
    }

    public function replaceEnvelopeDsn(string $body, string $dsn): string
    {
        $newline = strpos($body, "\n");

        if ($newline === false) {
            throw new InvalidArgumentException('The Sentry envelope header is incomplete.');
        }

        $header = json_decode(rtrim(substr($body, 0, $newline), "\r"), true);

        if (! is_array($header)) {
            throw new InvalidArgumentException('The Sentry envelope header is invalid.');
        }

        $header['dsn'] = $dsn;

        return json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ."\n".substr($body, $newline + 1);
    }
}
