<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\EncryptionKeyStatus;
use App\Models\Credential;
use App\Models\EncryptionKey;
use App\Models\Team;
use App\Support\Credentials\CredentialFileStorage;
use App\Support\Encryption\EncryptionKeyManager;
use App\Support\Encryption\EnvelopeEncryptor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesEncryptionKeys;
use Tests\TestCase;

class EncryptionKeyRotationTest extends TestCase
{
    use RefreshDatabase, UsesEncryptionKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureEncryptionKey();
    }

    public function test_credential_payload_is_encrypted_with_envelope_encryption(): void
    {
        $credential = Credential::factory()->create([
            'payload' => ['password' => 's3cret', 'key_id' => 'ABC123'],
        ]);

        $this->assertSame('s3cret', $credential->fresh()->payload['password']);

        $raw = DB::table('credentials')->where('id', $credential->id)->value('payload');
        $this->assertStringNotContainsString('s3cret', (string) $raw);
        $this->assertTrue(EnvelopeEncryptor::isEnvelopeEncrypted($raw));
    }

    public function test_credential_file_is_encrypted_with_v2_format(): void
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
        $this->assertStringStartsWith('APP_MANAGER_CREDENTIAL_FILE:v2:', $stored);
        $this->assertStringNotContainsString('private_key', $stored);
        $this->assertSame($plaintext, CredentialFileStorage::contents($media));
        $this->assertSame(2, $media->getCustomProperty('credential_file_encryption_version'));
        $this->assertNotNull($media->getCustomProperty('credential_file_encryption_key_id'));
    }

    public function test_legacy_v1_files_remain_readable(): void
    {
        Storage::fake(config('media-library.disk_name'));

        $credential = Credential::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'type' => CredentialType::AppleApiKey,
        ]);

        $plaintext = "-----BEGIN PRIVATE KEY-----\nlegacy-secret\n-----END PRIVATE KEY-----";
        $legacyEncrypted = "APP_MANAGER_CREDENTIAL_FILE:v1\n".Crypt::encryptString($plaintext);

        $media = $credential->addMediaFromString($legacyEncrypted)
            ->usingFileName('AuthKey.p8')
            ->withCustomProperties([
                'credential_file_encrypted' => true,
                'credential_file_encryption_version' => 1,
            ])
            ->toMediaCollection('file');

        $this->assertSame($plaintext, CredentialFileStorage::contents($media));
    }

    public function test_legacy_app_key_encrypted_payload_remains_readable(): void
    {
        $legacyEncrypted = Crypt::encryptString(json_encode(['password' => 'old-secret']));

        $credential = Credential::factory()->create();

        DB::table('credentials')
            ->where('id', $credential->id)
            ->update(['payload' => $legacyEncrypted]);

        $credential->refresh();
        $this->assertSame('old-secret', $credential->payload['password']);
    }

    public function test_dek_rotation_creates_new_active_key(): void
    {
        $originalKey = EncryptionKey::active()->first();

        $newKey = EncryptionKeyManager::rotateDEK();

        $this->assertNotSame($originalKey->id, $newKey->id);
        $this->assertSame(EncryptionKeyStatus::Active, $newKey->status);
        $this->assertSame(EncryptionKeyStatus::Retired, $originalKey->fresh()->status);
    }

    public function test_data_encrypted_with_retired_key_remains_readable(): void
    {
        $credential = Credential::factory()->create([
            'payload' => ['password' => 'before-rotation'],
        ]);

        EncryptionKeyManager::rotateDEK();
        EncryptionKeyManager::clearCache();

        $this->assertSame('before-rotation', $credential->fresh()->payload['password']);
    }

    public function test_files_encrypted_with_retired_key_remain_readable(): void
    {
        Storage::fake(config('media-library.disk_name'));

        $credential = Credential::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'type' => CredentialType::ApnsKey,
        ]);

        $plaintext = 'apns-key-content-before-rotation';
        $media = CredentialFileStorage::storeContents(
            $credential,
            $plaintext,
            'key.p8',
            'application/octet-stream',
        );

        EncryptionKeyManager::rotateDEK();
        EncryptionKeyManager::clearCache();

        $this->assertSame($plaintext, CredentialFileStorage::contents($media));
    }

    public function test_kek_rotation_re_encrypts_deks(): void
    {
        $oldKEK = config('app.key');
        $newDEK = EnvelopeEncryptor::generateDEK();
        $newKEK = 'base64:'.base64_encode($newDEK);

        $credential = Credential::factory()->create([
            'payload' => ['password' => 'kek-rotation-test'],
        ]);

        $count = EncryptionKeyManager::reEncryptAllDEKsWithNewKEK($oldKEK, $newKEK);
        $this->assertGreaterThan(0, $count);

        config(['app.key' => $newKEK]);
        app()->forgetInstance('encrypter');
        app()->singleton('encrypter', fn () => new \Illuminate\Encryption\Encrypter(
            base64_decode(substr($newKEK, 7), true),
            config('app.cipher'),
        ));
        \Illuminate\Support\Facades\Crypt::clearResolvedInstances();
        EncryptionKeyManager::clearCache();

        $this->assertSame('kek-rotation-test', $credential->fresh()->payload['password']);
    }

    public function test_migrate_command_creates_initial_key_and_encrypts_data(): void
    {
        EncryptionKey::query()->delete();
        EncryptionKeyManager::clearCache();

        $credential = Credential::factory()->create();

        $legacyEncrypted = Crypt::encryptString(json_encode(['password' => 'migrate-me']));
        DB::table('credentials')
            ->where('id', $credential->id)
            ->update(['payload' => $legacyEncrypted]);

        $this->artisan('encryption:migrate')
            ->assertSuccessful();

        $keyCount = EncryptionKey::count();
        $this->assertSame(1, $keyCount);

        $raw = DB::table('credentials')->where('id', $credential->id)->value('payload');
        $this->assertTrue(EnvelopeEncryptor::isEnvelopeEncrypted($raw));

        $credential->refresh();
        $this->assertSame('migrate-me', $credential->payload['password']);
    }

    public function test_migrate_command_fails_when_active_key_cannot_be_decrypted(): void
    {
        EncryptionKey::query()->delete();
        EncryptionKeyManager::clearCache();

        EncryptionKey::create([
            'version' => 1,
            'key_material' => 'written-under-a-different-app-key',
            'status' => EncryptionKeyStatus::Active,
        ]);

        $this->artisan('encryption:migrate')
            ->expectsOutputToContain('cannot be decrypted')
            ->assertFailed();
    }

    public function test_status_command_shows_key_info(): void
    {
        $this->artisan('encryption:status')
            ->expectsOutputToContain('Encryption Keys')
            ->expectsOutputToContain('v1')
            ->assertSuccessful();
    }

    public function test_active_key_caches_plain_attributes_instead_of_model_objects(): void
    {
        EncryptionKeyManager::clearCache();

        $key = EncryptionKeyManager::activeKey();

        $this->assertInstanceOf(EncryptionKey::class, $key);
        $this->assertIsArray(Cache::get('encryption_key:active'));

        $cachedKey = EncryptionKeyManager::activeKey();
        $this->assertTrue($key->is($cachedKey));
        $this->assertSame(EncryptionKeyStatus::Active, $cachedKey->status);
    }

    public function test_active_key_recovers_from_unreadable_cache_entry(): void
    {
        Cache::put('encryption_key:active', 'stale-or-incomplete-class', 3600);

        $key = EncryptionKeyManager::activeKey();

        $this->assertInstanceOf(EncryptionKey::class, $key);
        $this->assertIsArray(Cache::get('encryption_key:active'));
    }
}
