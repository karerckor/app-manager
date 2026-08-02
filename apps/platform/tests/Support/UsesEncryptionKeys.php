<?php

namespace Tests\Support;

use App\Support\Encryption\EncryptionKeyManager;

trait UsesEncryptionKeys
{
    protected function ensureEncryptionKey(): void
    {
        EncryptionKeyManager::createKey();
    }
}
