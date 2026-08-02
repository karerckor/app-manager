<?php

namespace Database\Factories;

use App\Enums\CredentialVerificationStatus;
use App\Enums\VaultMetadataSource;
use App\Enums\VaultProvider;
use App\Models\CredentialVaultReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CredentialVaultReference>
 */
class CredentialVaultReferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => VaultProvider::AwsSecretsManager,
            'resource' => 'arn:aws:secretsmanager:eu-central-1:123456789012:secret:'.fake()->slug(2),
            'metadata' => null,
            'metadata_version' => 1,
            'metadata_source' => VaultMetadataSource::User,
            'verification_status' => CredentialVerificationStatus::Unverified,
            'verified_at' => null,
            'verification_error' => null,
        ];
    }
}
