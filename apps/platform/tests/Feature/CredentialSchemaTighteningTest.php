<?php

namespace Tests\Feature;

use App\Models\AppIdentifier;
use App\Models\Credential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CredentialSchemaTighteningTest extends TestCase
{
    use RefreshDatabase;

    public function test_pivot_records_timestamps_on_attach(): void
    {
        $credential = Credential::factory()->create();
        $identifier = AppIdentifier::factory()->create();

        $credential->appIdentifiers()->attach($identifier);

        $pivot = $credential->appIdentifiers()->first()->pivot;
        $this->assertNotNull($pivot->created_at);
        $this->assertNotNull($pivot->updated_at);
    }

    public function test_expires_at_is_indexed(): void
    {
        $indexes = collect(Schema::getIndexes('credentials'));

        $this->assertTrue(
            $indexes->contains(fn ($index) => $index['columns'] === ['expires_at']),
            'Expected an index on credentials.expires_at'
        );
    }
}
