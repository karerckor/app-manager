<?php

namespace App\Console\Commands;

use App\Enums\EncryptionKeyStatus;
use App\Models\Credential;
use App\Models\EncryptionKey;
use App\Support\Encryption\EnvelopeEncryptor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EncryptionStatus extends Command
{
    protected $signature = 'encryption:status';

    protected $description = 'Show encryption key status and data migration progress';

    public function handle(): int
    {
        $keys = EncryptionKey::orderByDesc('version')->get();

        if ($keys->isEmpty()) {
            $this->warn('No encryption keys found. Run "encryption:migrate" to create the initial key.');

            return self::SUCCESS;
        }

        $this->info('Encryption Keys:');
        $rows = $keys->map(fn (EncryptionKey $key) => [
            "v{$key->version}",
            $key->id,
            $key->status->value,
            $key->created_at->toDateTimeString(),
        ]);
        $this->table(['Version', 'ID', 'Status', 'Created'], $rows->all());

        $totalCredentials = Credential::count();
        $migratedPayloads = 0;
        $legacyPayloads = 0;
        $nullPayloads = 0;

        Credential::query()->chunkById(500, function ($credentials) use (&$migratedPayloads, &$legacyPayloads, &$nullPayloads): void {
            foreach ($credentials as $credential) {
                $raw = DB::table('credentials')->where('id', $credential->id)->value('payload');

                if ($raw === null) {
                    $nullPayloads++;
                } elseif (EnvelopeEncryptor::isEnvelopeEncrypted($raw)) {
                    $migratedPayloads++;
                } else {
                    $legacyPayloads++;
                }
            }
        });

        $this->newLine();
        $this->info("Credential Payloads ({$totalCredentials} total):");
        $this->line("  Envelope encrypted (v2): {$migratedPayloads}");
        $this->line("  Legacy (APP_KEY direct): {$legacyPayloads}");
        $this->line("  Null/empty: {$nullPayloads}");

        return self::SUCCESS;
    }
}
