<?php

namespace App\Console\Commands;

use App\Support\Encryption\EncryptionKeyManager;
use Illuminate\Console\Command;

class EncryptionRotateKek extends Command
{
    protected $signature = 'encryption:rotate-kek
        {--old-key= : The previous APP_KEY (base64:... format)}
        {--new-key= : The new APP_KEY (base64:... format). Defaults to current APP_KEY}';

    protected $description = 'Re-encrypt all data encryption keys (DEKs) with a new key encryption key (APP_KEY)';

    public function handle(): int
    {
        $oldKEK = $this->option('old-key');
        $newKEK = $this->option('new-key') ?? config('app.key');

        if (! $oldKEK) {
            $this->error('The --old-key option is required.');

            return self::FAILURE;
        }

        if (! str_starts_with($oldKEK, 'base64:')) {
            $this->error('The --old-key must be in base64:... format.');

            return self::FAILURE;
        }

        $this->info('Re-encrypting all DEKs with the new KEK...');

        try {
            $count = EncryptionKeyManager::reEncryptAllDEKsWithNewKEK($oldKEK, $newKEK);
            $this->info("Successfully re-encrypted {$count} data encryption key(s).");
        } catch (\Throwable $e) {
            $this->error('Failed to re-encrypt DEKs: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
