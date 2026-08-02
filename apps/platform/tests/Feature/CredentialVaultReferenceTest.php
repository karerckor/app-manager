<?php

namespace Tests\Feature;

use App\Enums\CredentialStorageMode;
use App\Enums\CredentialVerificationStatus;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Models\Credential;
use App\Models\CredentialVaultReference;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CredentialVaultReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_state_creates_an_external_credential_with_a_reference(): void
    {
        $credential = Credential::factory()
            ->external(VaultProvider::HashicorpVault, 'secret/data/ios')
            ->create();

        $this->assertSame(CredentialStorageMode::External, $credential->storage_mode);
        $this->assertTrue($credential->isExternal());

        $reference = $credential->vaultReference;
        $this->assertNotNull($reference);
        $this->assertSame(VaultProvider::HashicorpVault, $reference->provider);
        $this->assertSame('secret/data/ios', $reference->resource);
        $this->assertSame(CredentialVerificationStatus::Unverified, $reference->verification_status);
        $this->assertSame(VaultMetadataSource::User, $reference->metadata_source);
        $this->assertSame(1, $reference->metadata_version);
        $this->assertSame($credential->team_id, $reference->team_id);
    }

    public function test_local_credential_has_no_reference(): void
    {
        $credential = Credential::factory()->create();

        $this->assertSame(CredentialStorageMode::Local, $credential->storage_mode);
        $this->assertFalse($credential->isExternal());
        $this->assertNull($credential->vaultReference);
    }

    public function test_deleting_the_credential_deletes_the_reference(): void
    {
        $credential = Credential::factory()->external()->create();
        $referenceId = $credential->vaultReference->id;

        $credential->delete();

        $this->assertDatabaseMissing('credential_vault_references', ['id' => $referenceId]);
    }

    public function test_a_credential_can_only_hold_one_reference(): void
    {
        $credential = Credential::factory()->external()->create();

        $this->expectException(QueryException::class);

        CredentialVaultReference::create([
            'credential_id' => $credential->id,
            'team_id' => $credential->team_id,
            'provider' => VaultProvider::Custom,
            'resource' => 'second-one',
            'metadata_source' => VaultMetadataSource::User,
            'verification_status' => CredentialVerificationStatus::Unverified,
        ]);
    }

    public function test_reference_changes_are_audited(): void
    {
        $credential = Credential::factory()->external()->create();

        $credential->vaultReference->update([
            'verification_status' => CredentialVerificationStatus::Verified,
            'verified_at' => now(),
        ]);

        $this->assertDatabaseHas('audits', [
            'auditable_type' => CredentialVaultReference::class,
            'auditable_id' => $credential->vaultReference->id,
            'event' => 'updated',
        ]);
    }
}
