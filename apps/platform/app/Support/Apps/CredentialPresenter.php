<?php

namespace App\Support\Apps;

use App\Enums\CredentialType;
use App\Enums\IntegrationStatus;
use App\Integrations\Expo\ExpoCredentialExporter;
use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\ExpoProject;
use App\Models\Profile;
use App\Models\Team;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;

class CredentialPresenter
{
    public static function present(
        Team $team,
        Credential $credential,
        AppIdentifier $identifier,
        ?Profile $profile,
        ?ExpoProject $expoProject = null,
    ): array {
        $media = $credential->getFirstMedia('file');
        $definition = CredentialSchema::definition($credential->type);
        $payload = $credential->payload ?? [];
        $reference = $credential->vaultReference;
        $passwordFieldsCount = collect($definition['fields'])
            ->filter(fn (array $field): bool => $field['input'] === 'password'
                && array_key_exists($field['name'], $payload))
            ->count();
        $expoLink = $expoProject?->credentialLinks
            ->first(fn ($link) => $link->credential_id === $credential->id
                && $link->app_identifier_id === $identifier->id);
        $requiresIosBuildCredentials = in_array($credential->type, [
            CredentialType::IosCertificate,
            CredentialType::ProvisioningProfile,
        ], true);
        $expoComplete = $expoLink !== null
            && (! $requiresIosBuildCredentials
                || filled(data_get($expoLink->metadata, 'ios_app_build_credentials_id')));

        return [
            'id' => $credential->id,
            'app_identifier_id' => $identifier->id,
            'name' => $credential->name,
            'tags' => $credential->tags ?? [],
            'tags_url' => route('accounts.credentials.tags.update', ['team' => $team, 'credential' => $credential]),
            'type' => $credential->type->value,
            'expires_at' => $credential->expires_at,
            'updated_at' => $credential->updated_at,
            'profile' => $profile ? BuildProfileSchema::definition($profile->type, $identifier->platform) : null,
            'fields' => array_keys($payload),
            'configured_fields' => array_keys($payload),
            'reveal_fields_count' => collect($definition['fields'])
                ->whereIn('name', array_keys($payload))
                ->count(),
            'payload' => collect($definition['fields'])->mapWithKeys(fn ($field) => [
                $field['name'] => $field['input'] === 'password'
                    ? ''
                    : ($payload[$field['name']] ?? ''),
            ]),
            'reveal_url' => $passwordFieldsCount > 0
                ? route('accounts.credentials.reveal', ['team' => $team, 'credential' => $credential])
                : null,
            'update_url' => route('accounts.credentials.update', ['team' => $team, 'credential' => $credential]),
            'detach_url' => route('accounts.apps.identifiers.credentials.detach', [
                'team' => $team,
                'mobileApp' => $identifier->mobileApp,
                'appIdentifier' => $identifier,
                'credential' => $credential,
                ...($profile ? ['profile_type' => $profile->type->value] : []),
            ]),
            'upload_url' => route('accounts.credentials.upload', ['team' => $team, 'credential' => $credential]),
            'file' => $media ? [
                'name' => $media->file_name,
                'size' => $media->size,
                'download_url' => route('accounts.credentials.download', ['team' => $team, 'credential' => $credential]),
            ] : null,
            'storage_mode' => $credential->storage_mode->value,
            'vault' => $reference ? [
                'provider' => $reference->provider->value,
                'provider_label' => $reference->provider->label(),
                'resource' => $reference->resource,
                'metadata' => $reference->metadata,
                'metadata_version' => $reference->metadata_version,
                'metadata_source' => $reference->metadata_source->value,
                'verification' => [
                    'status' => $reference->verification_status->value,
                    'verified_at' => $reference->verified_at,
                    'error' => $reference->verification_error,
                ],
            ] : null,
            'expo' => [
                'available' => $expoProject !== null
                    && $expoProject->integration->status === IntegrationStatus::Active
                    && $media !== null
                    && ExpoCredentialExporter::supports($credential->type),
                'pushed' => $expoLink !== null,
                'complete' => $expoComplete,
                'remote_resource_type' => $expoLink?->remote_resource_type,
                'remote_resource_id' => $expoLink?->remote_resource_id,
                'push_url' => $expoProject && $media
                    && ExpoCredentialExporter::supports($credential->type)
                    ? route('accounts.apps.expo.credentials.store', [
                        $team,
                        $identifier->mobileApp,
                        $identifier,
                        $credential,
                    ])
                    : null,
            ],
        ];
    }
}
