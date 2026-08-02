<?php

namespace App\Console\Commands;

use App\Support\Encryption\EncryptionKeyManager;
use Illuminate\Console\Command;

class EncryptionRotateDek extends Command
{
    protected $signature = 'encryption:rotate-dek
        {--force : Skip confirmation prompt}';

    protected $description = 'Create a new active data encryption key (DEK). Existing data remains decryptable with retired keys';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('This will retire the current DEK and create a new one. Continue?')) {
            return self::SUCCESS;
        }

        $newKey = EncryptionKeyManager::rotateDEK();

        $this->info("New active encryption key: v{$newKey->version} ({$newKey->id}).");
        $this->info('New credentials will be encrypted with this key. Existing data remains accessible via retired keys.');
        $this->info('Run "encryption:migrate" to re-encrypt existing data with the new key.');

        return self::SUCCESS;
    }
}
