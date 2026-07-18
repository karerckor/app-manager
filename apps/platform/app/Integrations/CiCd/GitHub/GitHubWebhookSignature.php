<?php

namespace App\Integrations\CiCd\GitHub;

class GitHubWebhookSignature
{
    public function valid(string $payload, ?string $signature): bool
    {
        $secret = (string) config('integrations.github.webhook_secret');

        if ($secret === '' || ! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }
}
