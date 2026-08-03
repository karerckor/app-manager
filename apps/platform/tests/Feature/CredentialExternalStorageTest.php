<?php

namespace Tests\Feature;

use App\Enums\CredentialStorageMode;
use App\Enums\CredentialType;
use App\Enums\Platform;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\MobileApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CredentialExternalStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_creates_an_external_credential_with_a_vault_reference(): void
    {
        [$owner, $team, $app, $identifier] = $this->workspace();

        $this->actingAs($owner)
            ->post(route('accounts.apps.credentials.store', [$team, $app]), [
                'app_identifier_id' => $identifier->id,
                'name' => 'APNs production',
                'type' => CredentialType::ApnsKey->value,
                'storage_mode' => CredentialStorageMode::External->value,
                'vault' => [
                    'provider' => VaultProvider::AwsSecretsManager->value,
                    'resource' => 'arn:aws:secretsmanager:eu-central-1:1:secret:apns',
                    'metadata' => ['region' => 'eu-central-1'],
                ],
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
                'expires_at' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect();

        $credential = $team->credentials()->firstOrFail();
        $this->assertSame(CredentialStorageMode::External, $credential->storage_mode);
        $this->assertSame(['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'], $credential->payload);
        $this->assertNull($credential->getFirstMedia('file'));

        $reference = $credential->vaultReference;
        $this->assertSame(VaultProvider::AwsSecretsManager, $reference->provider);
        $this->assertSame('arn:aws:secretsmanager:eu-central-1:1:secret:apns', $reference->resource);
        $this->assertSame(['region' => 'eu-central-1'], $reference->metadata);
        $this->assertSame(VaultMetadataSource::User, $reference->metadata_source);
        $this->assertSame(1, $reference->metadata_version);
        $this->assertTrue($identifier->credentials()->whereKey($credential->id)->exists());
    }

    public function test_external_creation_rejects_a_file_and_a_password_field(): void
    {
        [$owner, $team, $app, $identifier] = $this->workspace();

        $this->actingAs($owner)
            ->post(route('accounts.apps.credentials.store', [$team, $app]), [
                'app_identifier_id' => $identifier->id,
                'name' => 'Distribution certificate',
                'type' => CredentialType::IosCertificate->value,
                'storage_mode' => CredentialStorageMode::External->value,
                'profile_type' => 'app_store',
                'vault' => [
                    'provider' => VaultProvider::HashicorpVault->value,
                    'resource' => 'secret/data/ios',
                ],
                'payload' => ['password' => 'leaked'],
                'file' => UploadedFile::fake()->create('cert.p12', 4),
            ])
            ->assertSessionHasErrors(['file', 'payload.password']);

        $this->assertSame(0, $team->credentials()->count());
    }

    public function test_a_mode_the_team_does_not_permit_is_rejected(): void
    {
        [$owner, $team, $app, $identifier] = $this->workspace();
        $team->update(['allowed_credential_storage_modes' => ['local']]);

        $this->actingAs($owner)
            ->post(route('accounts.apps.credentials.store', [$team, $app]), [
                'app_identifier_id' => $identifier->id,
                'name' => 'APNs production',
                'type' => CredentialType::ApnsKey->value,
                'storage_mode' => CredentialStorageMode::External->value,
                'vault' => [
                    'provider' => VaultProvider::Custom->value,
                    'resource' => 'somewhere',
                ],
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            ])
            ->assertSessionHasErrors('storage_mode');
    }

    public function test_a_duplicate_vault_reference_is_reused_instead_of_created(): void
    {
        [$owner, $team, $app, $identifier] = $this->workspace();
        $team->update(['check_credential_uniqueness_by_default' => true]);
        $existing = Credential::factory()
            ->external(VaultProvider::AwsSecretsManager, 'arn:aws:secretsmanager:eu:1:secret:apns')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);

        $this->actingAs($owner)
            ->post(route('accounts.apps.credentials.store', [$team, $app]), [
                'app_identifier_id' => $identifier->id,
                'name' => 'APNs production again',
                'type' => CredentialType::ApnsKey->value,
                'storage_mode' => CredentialStorageMode::External->value,
                'vault' => [
                    'provider' => VaultProvider::AwsSecretsManager->value,
                    'resource' => 'arn:aws:secretsmanager:eu:1:secret:apns',
                ],
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            ])
            ->assertRedirect();

        $this->assertSame(1, $team->credentials()->count());
        $this->assertTrue($identifier->credentials()->whereKey($existing->id)->exists());
    }

    public function test_storage_mode_cannot_be_changed_on_update(): void
    {
        [$owner, $team] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::Custom, 'somewhere')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);

        $this->actingAs($owner)
            ->post(route('accounts.credentials.update', [$team, $credential]), [
                'name' => 'Renamed',
                'type' => CredentialType::ApnsKey->value,
                'storage_mode' => CredentialStorageMode::Local->value,
                'vault' => ['provider' => VaultProvider::Custom->value, 'resource' => 'somewhere'],
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            ])
            ->assertSessionHasErrors('storage_mode');

        $this->assertSame(CredentialStorageMode::External, $credential->fresh()->storage_mode);
    }

    public function test_updating_an_external_credential_rewrites_the_reference(): void
    {
        [$owner, $team] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::Custom, 'old-address')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);

        $this->actingAs($owner)
            ->post(route('accounts.credentials.update', [$team, $credential]), [
                'name' => 'APNs production',
                'type' => CredentialType::ApnsKey->value,
                'vault' => [
                    'provider' => VaultProvider::GcpSecretManager->value,
                    'resource' => 'projects/p/secrets/apns/versions/latest',
                    'metadata' => ['project' => 'p'],
                ],
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            ])
            ->assertRedirect();

        $reference = $credential->fresh()->vaultReference;
        $this->assertSame(VaultProvider::GcpSecretManager, $reference->provider);
        $this->assertSame('projects/p/secrets/apns/versions/latest', $reference->resource);
        $this->assertSame(2, $reference->metadata_version);
        $this->assertSame(VaultMetadataSource::User, $reference->metadata_source);
    }

    public function test_changing_only_the_resource_with_reordered_but_unchanged_metadata_does_not_bump_the_metadata_version(): void
    {
        [$owner, $team] = $this->workspace();
        $credential = Credential::factory()
            ->external(VaultProvider::Custom, 'old-address')
            ->create(['team_id' => $team->id, 'type' => CredentialType::ApnsKey]);
        $credential->vaultReference->update([
            'metadata' => ['project' => 'p', 'region' => 'eu-central-1'],
            'metadata_version' => 3,
            'metadata_source' => VaultMetadataSource::Pipeline,
        ]);

        $this->actingAs($owner)
            ->post(route('accounts.credentials.update', [$team, $credential]), [
                'name' => 'APNs production',
                'type' => CredentialType::ApnsKey->value,
                'vault' => [
                    'provider' => VaultProvider::Custom->value,
                    'resource' => 'new-address',
                    // Same pairs as stored, submitted in a different order.
                    'metadata' => ['region' => 'eu-central-1', 'project' => 'p'],
                ],
                'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
            ])
            ->assertRedirect();

        $reference = $credential->fresh()->vaultReference;
        $this->assertSame('new-address', $reference->resource);
        $metadata = $reference->metadata;
        ksort($metadata);
        $this->assertSame(['project' => 'p', 'region' => 'eu-central-1'], $metadata);
        $this->assertSame(3, $reference->metadata_version);
        $this->assertSame(VaultMetadataSource::Pipeline, $reference->metadata_source);
    }

    /** @return array{0: User, 1: Team, 2: MobileApp, 3: AppIdentifier} */
    private function workspace(): array
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->addUser($owner, 'owner');
        $owner->update(['current_team_id' => $team->id]);
        $app = MobileApp::factory()->create(['team_id' => $team->id]);
        $identifier = $app->appIdentifiers()->create([
            'platform' => Platform::Ios,
            'identifier' => 'com.example.atlas',
            'name' => 'Atlas iOS',
        ]);

        return [$owner->fresh(), $team->fresh(), $app, $identifier];
    }
}
