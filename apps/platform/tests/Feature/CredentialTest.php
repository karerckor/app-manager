<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_team(): void
    {
        $credential = Credential::factory()->create();

        $this->assertInstanceOf(Team::class, $credential->team);
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
