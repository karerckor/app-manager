<?php

namespace App\Console\Commands;

use App\Models\Credential;
use App\Models\EncryptionKey;
use App\Support\Credentials\CredentialFileStorage;
use App\Support\Encryption\EncryptionKeyManager;
use App\Support\Encryption\EnvelopeEncryptor;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

class EncryptionMigrate extends Command
{
    protected $signature = 'encryption:migrate
        {--dry-run : Show what would be migrated without making changes}
        {--force : Skip confirmation prompt}';

    protected $description = 'Ensure an active encryption key exists and re-encrypt all credential data with envelope encryption';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $activeKey = EncryptionKey::active()->orderByDesc('version')->first();

        if (! $activeKey) {
            $this->info('No active encryption key found. Creating initial key...');

            if ($dryRun) {
                $this->info('[dry-run] Would create initial encryption key.');
            } else {
                $activeKey = EncryptionKeyManager::createKey();
                $this->info("Created encryption key v{$activeKey->version} ({$activeKey->id}).");
            }
        } else {
            $this->info("Active encryption key: v{$activeKey->version} ({$activeKey->id}).");

            try {
                EncryptionKeyManager::decryptDEK($activeKey);
            } catch (DecryptException) {
                $this->error("The active encryption key v{$activeKey->version} cannot be decrypted with the current APP_KEY.");
                $this->error('If APP_KEY was rotated, run "encryption:rotate-kek --old-key=<previous APP_KEY>".');
                $this->error('If the previous APP_KEY is lost and the encrypted data is disposable, delete the encryption_keys rows and re-run this command.');

                return self::FAILURE;
            }
        }

        $migratedPayloads = 0;
        $migratedFiles = 0;
        $skippedPayloads = 0;
        $skippedFiles = 0;

        Credential::query()->with('media')->chunkById(100, function ($credentials) use (&$migratedPayloads, &$migratedFiles, &$skippedPayloads, &$skippedFiles, $dryRun): void {
            foreach ($credentials as $credential) {
                $rawPayload = DB::table('credentials')->where('id', $credential->id)->value('payload');

                if ($rawPayload !== null && ! EnvelopeEncryptor::isEnvelopeEncrypted($rawPayload)) {
                    $migratedPayloads++;

                    if (! $dryRun) {
                        $payload = $credential->payload;
                        if ($payload !== null) {
                            $credential->forceFill([]);
                            $credential->payload = $payload;
                            $credential->saveQuietly();
                        }
                    }
                } else {
                    $skippedPayloads++;
                }

                $media = $credential->getFirstMedia('file');
                if ($media) {
                    $version = $media->getCustomProperty('credential_file_encryption_version', 0);

                    if ($version < 2) {
                        $migratedFiles++;

                        if (! $dryRun) {
                            $contents = CredentialFileStorage::contents($media);
                            $media->delete();
                            CredentialFileStorage::storeContents(
                                $credential,
                                $contents,
                                $media->file_name,
                                $media->mime_type,
                            );
                        }
                    } else {
                        $skippedFiles++;
                    }
                }
            }
        });

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Credential payloads: {$migratedPayloads} migrated, {$skippedPayloads} already up-to-date.");
        $this->info("{$prefix}Credential files: {$migratedFiles} migrated, {$skippedFiles} already up-to-date.");

        return self::SUCCESS;
    }
}
