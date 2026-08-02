<?php

namespace App\Support\Encryption;

use RuntimeException;

final class EnvelopeEncryptor
{
    private const CIPHER = 'aes-256-cbc';

    public static function encrypt(string $plaintext, string $dek, string $keyId): string
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $dek, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return json_encode([
            'v' => 2,
            'kid' => $keyId,
            'ct' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
        ], JSON_THROW_ON_ERROR);
    }

    public static function decrypt(string $payload, string $dek): string
    {
        $data = json_decode($payload, true, 4, JSON_THROW_ON_ERROR);

        if (($data['v'] ?? null) !== 2 || ! isset($data['ct'], $data['iv'])) {
            throw new RuntimeException('Invalid envelope encryption payload format.');
        }

        $plaintext = openssl_decrypt(
            base64_decode($data['ct'], true),
            self::CIPHER,
            $dek,
            OPENSSL_RAW_DATA,
            base64_decode($data['iv'], true),
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed — wrong key or corrupted data.');
        }

        return $plaintext;
    }

    public static function extractKeyId(string $payload): ?string
    {
        $data = json_decode($payload, true);

        if (! is_array($data) || ($data['v'] ?? null) !== 2) {
            return null;
        }

        return $data['kid'] ?? null;
    }

    public static function isEnvelopeEncrypted(string $payload): bool
    {
        $data = json_decode($payload, true);

        return is_array($data) && ($data['v'] ?? null) === 2 && isset($data['kid'], $data['ct'], $data['iv']);
    }

    public static function generateDEK(): string
    {
        return random_bytes(32);
    }
}
