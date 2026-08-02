<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Team;
use App\Support\Credentials\CredentialFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesEncryptionKeys;
use Tests\TestCase;

class CredentialFileEncryptionTest extends TestCase
{
    use RefreshDatabase, UsesEncryptionKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureEncryptionKey();
    }

    public function test_new_credential_files_are_encrypted_at_rest_and_decrypt_for_consumers(): void
    {
        Storage::fake(config('media-library.disk_name'));
        $credential = Credential::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'type' => CredentialType::GooglePlayServiceAccount,
        ]);
        $plaintext = '{"type":"service_account","private_key":"secret"}';

        $media = CredentialFileStorage::storeContents(
            $credential,
            $plaintext,
            'service-account.json',
            'application/json',
        );
        $stored = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());

        $this->assertNotSame($plaintext, $stored);
        $this->assertStringNotContainsString('private_key', $stored);
        $this->assertSame($plaintext, CredentialFileStorage::contents($media));
        $this->assertTrue($media->getCustomProperty('credential_file_encrypted'));
        $this->assertSame(strlen($plaintext), $media->size);
        $this->assertSame('application/json', $media->mime_type);
    }

    public function test_migration_command_encrypts_legacy_files_idempotently(): void
    {
        Storage::fake(config('media-library.disk_name'));
        $credential = Credential::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'type' => CredentialType::AppleApiKey,
        ]);
        $plaintext = "-----BEGIN PRIVATE KEY-----\nlegacy-secret\n-----END PRIVATE KEY-----";
        $media = $credential->addMediaFromString($plaintext)
            ->usingFileName('AuthKey.p8')
            ->toMediaCollection('file');

        $this->artisan('credentials:encrypt-files', ['--dry-run' => true])
            ->expectsOutput('1 plaintext credential file(s) require encryption.')
            ->assertSuccessful();
        $this->assertSame($plaintext, Storage::disk($media->disk)->get($media->getPathRelativeToRoot()));

        $this->artisan('credentials:encrypt-files')
            ->expectsOutput('Encrypted 1 legacy credential file(s).')
            ->assertSuccessful();
        $media->refresh();
        $ciphertext = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
        $this->assertNotSame($plaintext, $ciphertext);
        $this->assertSame($plaintext, CredentialFileStorage::contents($media));

        $this->artisan('credentials:encrypt-files')
            ->expectsOutput('Encrypted 0 legacy credential file(s).')
            ->assertSuccessful();
        $this->assertSame($ciphertext, Storage::disk($media->disk)->get($media->getPathRelativeToRoot()));
    }
}
