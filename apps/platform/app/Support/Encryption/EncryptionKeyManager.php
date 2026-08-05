<?php

namespace App\Support\Encryption;

use App\Enums\EncryptionKeyStatus;
use App\Models\EncryptionKey;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

final class EncryptionKeyManager
{
    private const CACHE_KEY = 'encryption_key:active';

    private const CACHE_TTL = 3600;

    public static function activeKey(): EncryptionKey
    {
        // Cache raw attributes, not the model: cache.serializable_classes
        // forbids unserializing objects, so cached models come back as
        // __PHP_Incomplete_Class.
        $attributes = Cache::get(self::CACHE_KEY);

        if (! is_array($attributes)) {
            $attributes = EncryptionKey::active()->orderByDesc('version')->first()?->getAttributes();

            if ($attributes) {
                Cache::put(self::CACHE_KEY, $attributes, self::CACHE_TTL);
            }
        }

        if (! $attributes) {
            throw new RuntimeException('No active encryption key found. Run "encryption:migrate" first.');
        }

        return (new EncryptionKey)->newFromBuilder($attributes);
    }

    public static function findKey(string $keyId): EncryptionKey
    {
        $key = EncryptionKey::find($keyId);

        if (! $key) {
            throw new RuntimeException("Encryption key not found: {$keyId}");
        }

        return $key;
    }

    public static function decryptDEK(EncryptionKey $key): string
    {
        return Crypt::decryptString($key->key_material);
    }

    public static function createKey(): EncryptionKey
    {
        self::clearCache();

        $nextVersion = (EncryptionKey::max('version') ?? 0) + 1;
        $dek = EnvelopeEncryptor::generateDEK();

        return EncryptionKey::create([
            'version' => $nextVersion,
            'key_material' => Crypt::encryptString($dek),
            'status' => EncryptionKeyStatus::Active,
        ]);
    }

    public static function rotateDEK(): EncryptionKey
    {
        EncryptionKey::active()->update(['status' => EncryptionKeyStatus::Retired]);

        return self::createKey();
    }

    public static function reEncryptAllDEKsWithNewKEK(string $oldKEK, string $newKEK): int
    {
        $count = 0;

        foreach (EncryptionKey::all() as $key) {
            $plaintextDEK = self::decryptDEKWithKEK($key->key_material, $oldKEK);
            $key->update([
                'key_material' => self::encryptDEKWithKEK($plaintextDEK, $newKEK),
            ]);
            $count++;
        }

        self::clearCache();

        return $count;
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function decryptDEKWithKEK(string $encryptedDEK, string $kek): string
    {
        $encrypter = self::buildEncrypter($kek);

        return $encrypter->decryptString($encryptedDEK);
    }

    private static function encryptDEKWithKEK(string $plaintextDEK, string $kek): string
    {
        $encrypter = self::buildEncrypter($kek);

        return $encrypter->encryptString($plaintextDEK);
    }

    private static function buildEncrypter(string $kek): Encrypter
    {
        $rawKey = str_starts_with($kek, 'base64:')
            ? base64_decode(substr($kek, 7), true)
            : $kek;

        return new Encrypter($rawKey, config('app.cipher'));
    }
}
