<?php

namespace App\Integrations\Webhooks;

use InvalidArgumentException;

class WebhookUrlGuard
{
    public function assertSafe(string $url): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Enter a valid webhook URL.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Webhook URLs must use HTTP or HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Webhook URLs cannot contain credentials.');
        }

        if (app()->environment('production') && $scheme !== 'https') {
            throw new InvalidArgumentException('Webhook URLs must use HTTPS in production.');
        }

        if (config('webhooks.allow_private_addresses')) {
            return;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolve($host);

        if ($addresses === []) {
            throw new InvalidArgumentException('The webhook hostname could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (! filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            )) {
                throw new InvalidArgumentException('Webhook URLs cannot resolve to private or reserved addresses.');
            }
        }
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ))));
    }
}
