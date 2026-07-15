<?php

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Support\Credentials\CredentialSchema;
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
        $json = CredentialSchema::definition(
            CredentialType::GoogleServicesJson,
        );
        $this->assertSame(['android'], $json['platforms']);
        $this->assertSame('identifier', $json['attachment']);
        $this->assertSame(['json'], $json['file']['extensions']);
        $this->assertSame([], $json['fields']);

        $plist = CredentialSchema::definition(
            CredentialType::GoogleServiceInfoPlist,
        );
        $this->assertSame(['ios'], $plist['platforms']);
        $this->assertSame('identifier', $plist['attachment']);
        $this->assertSame(['plist'], $plist['file']['extensions']);
        $this->assertSame([], $plist['fields']);
    }

    public function test_only_apple_signing_types_are_marked_as_expiring(): void
    {
        $expiring = [CredentialType::IosCertificate, CredentialType::ProvisioningProfile];

        foreach (CredentialType::cases() as $type) {
            $shouldExpire = in_array($type, $expiring, true);

            $this->assertSame($shouldExpire, CredentialSchema::definition($type)['expires']);
            $this->assertSame($shouldExpire, CredentialSchema::expires($type));
        }
    }
}
