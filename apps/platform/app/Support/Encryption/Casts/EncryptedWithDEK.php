<?php

namespace App\Support\Encryption\Casts;

use App\Support\Encryption\EncryptionKeyManager;
use App\Support\Encryption\EnvelopeEncryptor;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class EncryptedWithDEK implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

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

    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $activeKey = EncryptionKeyManager::activeKey();
        $dek = EncryptionKeyManager::decryptDEK($activeKey);

        return EnvelopeEncryptor::encrypt($value, $dek, $activeKey->id);
    }
}
