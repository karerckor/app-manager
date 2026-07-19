<?php

namespace App\Support\Apps;

use App\Models\AppIdentifier;
use App\Models\Credential;
use App\Models\Profile;
use App\Models\Team;
use App\Support\Credentials\BuildProfileSchema;
use App\Support\Credentials\CredentialSchema;

class CredentialPresenter
{
    public static function present(Team $team, Credential $credential, AppIdentifier $identifier, ?Profile $profile): array
    {
        $media = $credential->getFirstMedia('file');
        $definition = CredentialSchema::definition($credential->type);
        $payload = $credential->payload ?? [];

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
            'payload' => collect($definition['fields'])->mapWithKeys(fn ($field) => [
                $field['name'] => $field['input'] === 'password'
                    ? ''
                    : ($payload[$field['name']] ?? ''),
            ]),
            'update_url' => route('accounts.credentials.update', ['team' => $team, 'credential' => $credential]),
            'delete_url' => route('accounts.credentials.destroy', ['team' => $team, 'credential' => $credential]),
            'attached_elsewhere' => $credential->isAttachedOutsideApp($identifier->mobile_app_id),
            'upload_url' => route('accounts.credentials.upload', ['team' => $team, 'credential' => $credential]),
            'file' => $media ? [
                'name' => $media->file_name,
                'size' => $media->size,
                'download_url' => route('accounts.credentials.download', ['team' => $team, 'credential' => $credential]),
            ] : null,
        ];
    }
}
