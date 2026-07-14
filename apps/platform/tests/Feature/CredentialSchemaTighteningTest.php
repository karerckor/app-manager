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

    public function test_google_services_client_config_types_are_identifier_scoped_file_only(): void
    {
        $json = \App\Support\Credentials\CredentialSchema::definition(
            \App\Enums\CredentialType::GoogleServicesJson,
        );
        $this->assertSame(['android'], $json['platforms']);
        $this->assertSame('identifier', $json['attachment']);
        $this->assertSame(['json'], $json['file']['extensions']);
        $this->assertSame([], $json['fields']);

        $plist = \App\Support\Credentials\CredentialSchema::definition(
            \App\Enums\CredentialType::GoogleServiceInfoPlist,
        );
        $this->assertSame(['ios'], $plist['platforms']);
        $this->assertSame('identifier', $plist['attachment']);
        $this->assertSame(['plist'], $plist['file']['extensions']);
        $this->assertSame([], $plist['fields']);
    }
}
