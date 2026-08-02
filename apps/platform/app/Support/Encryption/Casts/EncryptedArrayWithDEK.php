<?php

namespace App\Support\Encryption\Casts;

use App\Support\Encryption\EncryptionKeyManager;
use App\Support\Encryption\EnvelopeEncryptor;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class EncryptedArrayWithDEK implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decrypted = $this->decryptValue($value);

        $decoded = json_decode($decrypted, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $json = json_encode($value);

        $activeKey = EncryptionKeyManager::activeKey();
        $dek = EncryptionKeyManager::decryptDEK($activeKey);

        return EnvelopeEncryptor::encrypt($json, $dek, $activeKey->id);
    }

    private function decryptValue(string $value): string
    {
        if (EnvelopeEncryptor::isEnvelopeEncrypted($value)) {
            $keyId = EnvelopeEncryptor::extractKeyId($value);
            $encryptionKey = EncryptionKeyManager::findKey($keyId);
            $dek = EncryptionKeyManager::decryptDEK($encryptionKey);

            return EnvelopeEncryptor::decrypt($value, $dek);
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value;
        }
    }
}
