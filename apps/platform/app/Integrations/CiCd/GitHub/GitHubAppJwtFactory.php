<?php

namespace App\Integrations\CiCd\GitHub;

use RuntimeException;

class GitHubAppJwtFactory
{
    public function make(): string
    {
        $appId = (string) config('integrations.github.app_id');
        $privateKey = $this->privateKey();

        if ($appId === '' || $privateKey === '') {
            throw new RuntimeException('GitHub App ID and private key must be configured.');
        }

        $now = time();
        $header = $this->encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = $this->encode([
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => $appId,
        ]);
        $unsigned = $header.'.'.$payload;
        $signed = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new RuntimeException('Unable to sign the GitHub App JWT.');
        }

        return $unsigned.'.'.$this->base64Url($signature);
    }

    private function privateKey(): string
    {
        $configured = (string) config('integrations.github.private_key');

        if ($configured !== '' && is_file($configured)) {
            $contents = file_get_contents($configured);

            return is_string($contents) ? $contents : '';
        }

        return str_replace('\\n', "\n", $configured);
    }

    /** @param array<string, int|string> $value */
    private function encode(array $value): string
    {
        return $this->base64Url(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
