<?php

namespace Tests\Unit;

use App\Integrations\CiCd\GitHub\GitHubAppJwtFactory;
use Tests\TestCase;

class GitHubAppJwtFactoryTest extends TestCase
{
    public function test_it_creates_a_short_lived_rs256_app_jwt(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $privateKey);
        $publicKey = openssl_pkey_get_details($key)['key'];
        config()->set('integrations.github.app_id', '12345');
        config()->set('integrations.github.private_key', $privateKey);

        $jwt = (new GitHubAppJwtFactory)->make();
        [$encodedHeader, $encodedPayload, $encodedSignature] = explode('.', $jwt);
        $header = json_decode($this->decode($encodedHeader), true, flags: JSON_THROW_ON_ERROR);
        $payload = json_decode($this->decode($encodedPayload), true, flags: JSON_THROW_ON_ERROR);
        $signature = $this->decode($encodedSignature);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('12345', $payload['iss']);
        $this->assertLessThanOrEqual(600, $payload['exp'] - $payload['iat']);
        $this->assertSame(
            1,
            openssl_verify($encodedHeader.'.'.$encodedPayload, $signature, $publicKey, OPENSSL_ALGO_SHA256),
        );
    }

    private function decode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
