<?php

namespace App\Rules;

use App\Enums\CredentialType;
use App\Support\Credentials\CredentialSchema;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ValidCredentialFile implements ValidationRule
{
    public function __construct(
        private readonly CredentialType $type,
        private readonly array $payload = [],
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());
        $allowed = CredentialSchema::definition($this->type)['file']['extensions'];

        if (! in_array($extension, $allowed, true)) {
            $fail('The credential file must have one of these extensions: '.implode(', ', $allowed).'.');

            return;
        }

        $contents = $value->get();

        if ($contents === '') {
            $fail('The credential file cannot be empty.');

            return;
        }

        match ($this->type) {
            CredentialType::IosCertificate => $this->validatePkcs12($contents, $fail),
            CredentialType::ApnsKey,
            CredentialType::AppleApiKey => $this->validatePrivateKey($contents, $fail),
            CredentialType::FirebaseConfig,
            CredentialType::GooglePlayServiceAccount => $this->validateServiceAccount($contents, $fail),
            CredentialType::GoogleServicesJson => $this->validateGoogleServicesJson($contents, $fail),
            CredentialType::GoogleServiceInfoPlist => $this->validateGoogleServiceInfoPlist($contents, $fail),
            default => null,
        };
    }

    private function validatePkcs12(string $contents, Closure $fail): void
    {
        $certificates = [];

        if (! @openssl_pkcs12_read($contents, $certificates, (string) ($this->payload['password'] ?? ''))) {
            $fail('The PKCS#12 file is invalid or the certificate password is incorrect.');
        }
    }

    private function validatePrivateKey(string $contents, Closure $fail): void
    {
        if (! str_contains($contents, 'BEGIN PRIVATE KEY') || @openssl_pkey_get_private($contents) === false) {
            $fail('The .p8 file must contain a valid PEM private key.');
        }
    }

    private function validateServiceAccount(string $contents, Closure $fail): void
    {
        try {
            $json = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $fail('The credential file must contain valid JSON.');

            return;
        }

        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (! is_string($json[$key] ?? null) || blank($json[$key])) {
                $fail("The service-account JSON must contain a non-empty {$key} value.");

                return;
            }
        }

        if (($json['type'] ?? null) !== 'service_account') {
            $fail('The JSON type must be service_account.');

            return;
        }

        if (! str_contains($json['private_key'], 'BEGIN PRIVATE KEY') || @openssl_pkey_get_private($json['private_key']) === false) {
            $fail('The service-account JSON contains an invalid private_key.');
        }
    }

    private function validateGoogleServicesJson(string $contents, Closure $fail): void
    {
        try {
            $json = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $fail('The google-services.json file must contain valid JSON.');

            return;
        }

        if (! is_array($json['project_info'] ?? null) || blank($json['project_info'])) {
            $fail('The google-services.json file must contain a non-empty project_info object.');

            return;
        }

        if (! is_array($json['client'] ?? null) || blank($json['client'])) {
            $fail('The google-services.json file must contain a non-empty client array.');
        }
    }

    private function validateGoogleServiceInfoPlist(string $contents, Closure $fail): void
    {
        if (@simplexml_load_string($contents, 'SimpleXMLElement', LIBXML_NONET) === false) {
            $fail('The GoogleService-Info.plist file must be a well-formed plist.');

            return;
        }

        $hasIdentity = str_contains($contents, '<key>GOOGLE_APP_ID</key>')
            || str_contains($contents, '<key>PROJECT_ID</key>');

        if (! str_contains($contents, '<key>BUNDLE_ID</key>') || ! $hasIdentity) {
            $fail('The GoogleService-Info.plist file must contain BUNDLE_ID and a Firebase project identifier.');
        }
    }
}
