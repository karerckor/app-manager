<?php

namespace App\Console\Commands;

use App\Models\Credential;
use App\Support\Credentials\CredentialFileStorage;
use Illuminate\Console\Command;

class EncryptCredentialFiles extends Command
{
    protected $signature = 'credentials:encrypt-files {--dry-run : Count plaintext files without changing them}';

    protected $description = 'Encrypt legacy credential media files with the application key';

    public function handle(): int
    {
        $encrypted = 0;
        $dryRun = (bool) $this->option('dry-run');

        Credential::query()->with('media')->chunkById(100, function ($credentials) use (&$encrypted, $dryRun): void {
            foreach ($credentials as $credential) {
                $media = $credential->getFirstMedia('file');

                if (! $media || $media->getCustomProperty('credential_file_encrypted', false)) {
                    continue;
                }

                $encrypted++;

                if (! $dryRun) {
                    CredentialFileStorage::encryptExisting($media);
                }
            }
        });

        $this->info($dryRun
            ? "{$encrypted} plaintext credential file(s) require encryption."
            : "Encrypted {$encrypted} legacy credential file(s).");

        return self::SUCCESS;
    }
}
