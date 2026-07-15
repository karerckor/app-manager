<?php

namespace App\Support\Credentials;

use App\Enums\CredentialType;
use App\Enums\ProfileType;
use App\Rules\ValidCredentialFile;
use Illuminate\Validation\Rule;

class CredentialSchema
{
    public static function definitions(): array
    {
        return array_map(
            fn (CredentialType $type) => self::definition($type),
            CredentialType::cases(),
        );
    }

    public static function definition(CredentialType $type): array
    {
        return match ($type) {
            CredentialType::IosCertificate => self::make(
                $type,
                'iOS signing certificate',
                ['ios'],
                ['p12', 'pfx'],
                'Password-protected PKCS#12 certificate (.p12 or .pfx)',
                [
                    self::field('password', 'Certificate password', 'password'),
                ],
                profileScoped: true,
                expires: true,
            ),
            CredentialType::ProvisioningProfile => self::make(
                $type,
                'Provisioning profile',
                ['ios'],
                ['mobileprovision'],
                'Apple provisioning profile (.mobileprovision)',
                [],
                profileScoped: true,
                expires: true,
            ),
            CredentialType::ApnsKey => self::make(
                $type,
                'APNs authentication key',
                ['ios'],
                ['p8'],
                'Apple private key (.p8)',
                [
                    self::field('key_id', 'Key ID', 'text', '10-character ID from Apple Developer'),
                    self::field('team_id', 'Team ID', 'text', '10-character Apple Developer Team ID'),
                ],
            ),
            CredentialType::AppleApiKey => self::make(
                $type,
                'App Store Connect API key',
                ['ios'],
                ['p8'],
                'App Store Connect private key (.p8)',
                [
                    self::field('key_id', 'Key ID', 'text', '10-character App Store Connect key ID'),
                    self::field('issuer_id', 'Issuer ID', 'text', 'UUID from App Store Connect'),
                ],
            ),
            CredentialType::AndroidKeystore => self::make(
                $type,
                'Android keystore',
                ['android'],
                ['jks', 'keystore'],
                'Java or Android keystore (.jks or .keystore)',
                [
                    self::field('store_password', 'Store password', 'password'),
                    self::field('key_alias', 'Key alias'),
                    self::field('key_password', 'Key password', 'password'),
                ],
                true,
            ),
            CredentialType::FirebaseConfig => self::make(
                $type,
                'Firebase HTTP v1 service account',
                ['ios', 'android'],
                ['json'],
                'Service-account JSON containing project_id, client_email and private_key',
            ),
            CredentialType::GooglePlayServiceAccount => self::make(
                $type,
                'Google Play service account',
                ['android'],
                ['json'],
                'Google service-account JSON containing project_id, client_email and private_key',
            ),
            CredentialType::GoogleServicesJson => self::make(
                $type,
                'Firebase google-services.json',
                ['android'],
                ['json'],
                'Firebase client config for Android (google-services.json)',
            ),
            CredentialType::GoogleServiceInfoPlist => self::make(
                $type,
                'Firebase GoogleService-Info.plist',
                ['ios'],
                ['plist'],
                'Firebase client config for iOS (GoogleService-Info.plist)',
            ),
        };
    }

    public static function rules(CredentialType $type, bool $fileRequired = true, array $payload = []): array
    {
        $definition = self::definition($type);
        $fieldNames = array_column($definition['fields'], 'name');
        $payloadRules = $fieldNames
            ? ['required', 'array:'.implode(',', $fieldNames)]
            : ['nullable', 'array', 'max:0'];

        $rules = [
            'payload' => $payloadRules,
            'file' => [
                $fileRequired ? 'required' : 'nullable',
                'file',
                'max:20480',
                new ValidCredentialFile($type, $payload),
            ],
        ];

        foreach ($definition['fields'] as $field) {
            $rules['payload.'.$field['name']] = match ($field['name']) {
                'key_id', 'team_id' => ['required', 'string', 'size:10', 'regex:/^[A-Z0-9]+$/'],
                'issuer_id' => ['required', 'uuid'],
                default => ['required', 'string', 'max:2000'],
            };
        }

        return $rules;
    }

    public static function supportsPlatform(CredentialType $type, string $platform): bool
    {
        return in_array($platform, self::definition($type)['platforms'], true);
    }

    public static function requiresProfile(CredentialType $type): bool
    {
        return self::definition($type)['attachment'] === 'profile';
    }

    public static function expires(CredentialType $type): bool
    {
        return self::definition($type)['expires'];
    }

    public static function attachmentRules(CredentialType $type): array
    {
        return [
            'profile_type' => self::requiresProfile($type)
                ? ['required', Rule::enum(ProfileType::class)]
                : ['prohibited'],
        ];
    }

    private static function make(
        CredentialType $type,
        string $label,
        array $platforms,
        array $extensions,
        string $fileHint,
        array $fields = [],
        bool $profileScoped = false,
        bool $expires = false,
    ): array {
        return [
            'value' => $type->value,
            'label' => $label,
            'platforms' => $platforms,
            'attachment' => $profileScoped ? 'profile' : 'identifier',
            'expires' => $expires,
            'profiles' => $profileScoped
                ? collect($platforms)->flatMap(fn (string $platform) => BuildProfileSchema::forPlatform($platform))->values()->all()
                : [],
            'fields' => $fields,
            'file' => [
                'required' => true,
                'extensions' => $extensions,
                'accept' => implode(',', array_map(fn ($extension) => '.'.$extension, $extensions)),
                'hint' => $fileHint,
            ],
        ];
    }

    private static function field(
        string $name,
        string $label,
        string $input = 'text',
        ?string $hint = null,
    ): array {
        return compact('name', 'label', 'input', 'hint') + ['required' => true];
    }
}
