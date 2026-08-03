<?php

namespace Tests\Unit;

use App\Enums\CredentialType;
use App\Enums\VaultProvider;
use App\Support\Credentials\CredentialSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CredentialSchemaExternalRulesTest extends TestCase
{
    public function test_apns_key_keeps_its_non_secret_fields(): void
    {
        $validator = Validator::make([
            'vault' => [
                'provider' => VaultProvider::AwsSecretsManager->value,
                'resource' => 'arn:aws:secretsmanager:eu-central-1:1:secret:apns',
            ],
            'payload' => ['key_id' => 'ABCDEFGHIJ', 'team_id' => 'KLMNOPQRST'],
        ], CredentialSchema::externalRules(CredentialType::ApnsKey));

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_keystore_rejects_password_fields(): void
    {
        $validator = Validator::make([
            'vault' => [
                'provider' => VaultProvider::HashicorpVault->value,
                'resource' => 'secret/data/android',
            ],
            'payload' => [
                'key_alias' => 'release',
                'store_password' => 'should-not-be-here',
            ],
        ], CredentialSchema::externalRules(CredentialType::AndroidKeystore));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('payload.store_password', $validator->errors()->messages());
    }

    public function test_a_file_is_prohibited(): void
    {
        $validator = Validator::make([
            'vault' => [
                'provider' => VaultProvider::Custom->value,
                'resource' => 'anywhere',
            ],
            'payload' => [],
            'file' => UploadedFile::fake()->create('cert.p12', 4),
        ], CredentialSchema::externalRules(CredentialType::IosCertificate));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('file', $validator->errors()->messages());
    }

    public function test_a_provisioning_profile_needs_only_the_vault_block(): void
    {
        $validator = Validator::make([
            'vault' => [
                'provider' => VaultProvider::FastlaneMatch->value,
                'resource' => 'git@github.com:acme/certs.git#appstore/com.acme.app',
            ],
        ], CredentialSchema::externalRules(CredentialType::ProvisioningProfile));

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_the_vault_block_is_required_and_validated(): void
    {
        $validator = Validator::make([
            'vault' => ['provider' => 'not-a-provider', 'resource' => ''],
            'payload' => [],
        ], CredentialSchema::externalRules(CredentialType::IosCertificate));

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->messages();
        $this->assertArrayHasKey('vault.provider', $errors);
        $this->assertArrayHasKey('vault.resource', $errors);
    }

    public function test_nested_metadata_is_rejected(): void
    {
        $validator = Validator::make([
            'vault' => [
                'provider' => VaultProvider::Custom->value,
                'resource' => 'anywhere',
                'metadata' => ['region' => ['nested' => 'value']],
            ],
            'payload' => [],
        ], CredentialSchema::externalRules(CredentialType::IosCertificate));

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('vault.metadata.region', $validator->errors()->messages());
    }
}
