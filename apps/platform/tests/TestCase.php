<?php

namespace Tests;

use App\Models\EncryptionKey;
use App\Support\Encryption\EncryptionKeyManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        try {
            if (EncryptionKey::count() === 0) {
                EncryptionKeyManager::createKey();
            }
        } catch (\Throwable) {
        }
    }
}
