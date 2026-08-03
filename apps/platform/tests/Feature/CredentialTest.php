<?php

namespace Tests\Feature;

use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsesEncryptionKeys;
use Tests\TestCase;

class CredentialTest extends TestCase
{
    use RefreshDatabase, UsesEncryptionKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureEncryptionKey();
    }

    public function test_belongs_to_team(): void
    {
        $credential = Credential::factory()->create();

        $this->assertInstanceOf(Team::class, $credential->team);
    }

    public function test_a_freshly_instantiated_credential_defaults_to_local_storage_mode(): void
    {
        $this->assertSame(CredentialStorageMode::Local, (new Credential)->storage_mode);
    }

    public function test_a_credential_created_without_an_explicit_storage_mode_reports_local_before_any_refresh(): void
    {
        $credential = Team::factory()->create()->credentials()->create([
            'type' => CredentialType::ApnsKey,
            'name' => 'No storage mode passed',
        ]);

        $this->assertSame(CredentialStorageMode::Local, $credential->storage_mode);
        $this->assertSame(CredentialStorageMode::Local, $credential->fresh()->storage_mode);
    }

    public function test_type_is_cast_to_enum(): void
    {
        $credential = Credential::factory()->create(['type' => CredentialType::ApnsKey]);

        $this->assertSame(CredentialType::ApnsKey, $credential->fresh()->type);
    }

    public function test_payload_round_trips_as_array_and_is_encrypted_at_rest(): void
    {
        $credential = Credential::factory()->create([
            'payload' => ['password' => 's3cret', 'key_id' => 'ABC123'],
        ]);

        $this->assertSame('s3cret', $credential->fresh()->payload['password']);

        $raw = DB::table('credentials')->where('id', $credential->id)->value('payload');
        $this->assertStringNotContainsString('s3cret', (string) $raw);
    }

    public function test_registers_file_media_collection_as_single_file(): void
    {
        $credential = Credential::factory()->create();
        $file = $credential->getRegisteredMediaCollections()->firstWhere('name', 'file');

        $this->assertNotNull($file);
        $this->assertTrue($file->singleFile);
    }
}
