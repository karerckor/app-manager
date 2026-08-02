<?php

namespace App\Enums;

enum VaultProvider: string
{
    case AwsSecretsManager = 'aws_secrets_manager';
    case HashicorpVault = 'hashicorp_vault';
    case AzureKeyVault = 'azure_key_vault';
    case GcpSecretManager = 'gcp_secret_manager';
    case FastlaneMatch = 'fastlane_match';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::AwsSecretsManager => 'AWS Secrets Manager',
            self::HashicorpVault => 'HashiCorp Vault',
            self::AzureKeyVault => 'Azure Key Vault',
            self::GcpSecretManager => 'Google Secret Manager',
            self::FastlaneMatch => 'fastlane match',
            self::Custom => 'Custom',
        };
    }

    public function resourcePlaceholder(): string
    {
        return match ($this) {
            self::AwsSecretsManager => 'arn:aws:secretsmanager:eu-central-1:123456789012:secret:name-AbCdEf',
            self::HashicorpVault => 'secret/data/mobile/ios-distribution',
            self::AzureKeyVault => 'https://my-vault.vault.azure.net/secrets/ios-distribution',
            self::GcpSecretManager => 'projects/my-project/secrets/ios-distribution/versions/latest',
            self::FastlaneMatch => 'git@github.com:acme/certificates.git#appstore/com.acme.app',
            self::Custom => 'Any address your pipeline understands',
        };
    }

    /** @return list<array{value: string, label: string, placeholder: string}> */
    public static function definitions(): array
    {
        return array_map(fn (self $provider): array => [
            'value' => $provider->value,
            'label' => $provider->label(),
            'placeholder' => $provider->resourcePlaceholder(),
        ], self::cases());
    }
}
