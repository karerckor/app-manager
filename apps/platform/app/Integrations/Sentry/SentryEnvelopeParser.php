<?php

namespace App\Integrations\Sentry;

use InvalidArgumentException;
use JsonException;

class SentryEnvelopeParser
{
    public function parse(string $body): ParsedEnvelope
    {
        [$header, $offset] = $this->readHeader($body, 0, 'envelope');
        $items = [];
        $length = strlen($body);

        while ($offset < $length) {
            while ($offset < $length && ($body[$offset] === "\n" || $body[$offset] === "\r")) {
                $offset++;
            }

            if ($offset >= $length) {
                break;
            }

            [$itemHeader, $offset] = $this->readHeader($body, $offset, 'item');
            $declaredLength = $itemHeader['length'] ?? null;

            if ($declaredLength !== null) {
                if (! is_int($declaredLength) || $declaredLength < 0 || $offset + $declaredLength > $length) {
                    throw new InvalidArgumentException('The Sentry envelope contains an invalid item length.');
                }

                $payload = substr($body, $offset, $declaredLength);
                $offset += $declaredLength;
            } else {
                $newline = strpos($body, "\n", $offset);
                $payload = $newline === false
                    ? substr($body, $offset)
                    : rtrim(substr($body, $offset, $newline - $offset), "\r");
                $offset = $newline === false ? $length : $newline;
            }

            $items[] = [
                'header' => $itemHeader,
                'payload' => $payload,
                'json' => $this->decodeJsonItem($itemHeader, $payload),
            ];
        }

        if ($items === []) {
            throw new InvalidArgumentException('The Sentry envelope does not contain any items.');
        }

        return new ParsedEnvelope($header, $items);
    }

    /** @return array{array<string, mixed>, int} */
    private function readHeader(string $body, int $offset, string $kind): array
    {
        $newline = strpos($body, "\n", $offset);

        if ($newline === false) {
            throw new InvalidArgumentException("The Sentry {$kind} header is incomplete.");
        }

        $line = rtrim(substr($body, $offset, $newline - $offset), "\r");

        try {
            $header = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException("The Sentry {$kind} header is not valid JSON.");
        }

        if (! is_array($header)) {
            throw new InvalidArgumentException("The Sentry {$kind} header must be an object.");
        }

        return [$header, $newline + 1];
    }

    /** @return array<string, mixed>|null */
    private function decodeJsonItem(array $header, string $payload): ?array
    {
        $type = $header['type'] ?? null;

        if (! in_array($type, ['event', 'transaction', 'profile', 'client_report', 'session', 'sessions'], true)) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
