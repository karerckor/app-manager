<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CredentialVaultReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CredentialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = $this->resource->getFirstMedia('file');
        $mobileApp = $request->route('mobileApp');

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'tags' => $this->tags ?? [],
            'expires_at' => $this->expires_at,
            'app_identifiers' => AppIdentifierResource::collection($this->whenLoaded('appIdentifiers')),
            'profiles' => $this->whenLoaded('profiles', fn () => $this->profiles->map(fn ($profile) => [
                'id' => $profile->id,
                'type' => $profile->type->value,
                'name' => $profile->name,
                'app_identifier' => [
                    'id' => $profile->appIdentifier->id,
                    'platform' => $profile->appIdentifier->platform->value,
                    'identifier' => $profile->appIdentifier->identifier,
                ],
            ])->values()),
            'file' => $media ? [
                'name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'url' => route('api.v1.apps.credentials.file.show', [
                    'team' => $request->route('team'),
                    'mobileApp' => $mobileApp,
                    'credential' => $this->resource,
                ]),
            ] : null,
            'storage_mode' => $this->storage_mode->value,
            'vault' => $this->resource->relationLoaded('vaultReference')
                ? $this->vaultBlock($this->resource->vaultReference)
                : $this->vaultBlock($this->resource->vaultReference()->first()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** @return array<string, mixed>|null */
    private function vaultBlock(?CredentialVaultReference $reference): ?array
    {
        if (! $reference) {
            return null;
        }

        return [
            'provider' => $reference->provider->value,
            'resource' => $reference->resource,
            'metadata' => $reference->metadata,
            'metadata_version' => $reference->metadata_version,
            'metadata_source' => $reference->metadata_source->value,
            'verification' => [
                'status' => $reference->verification_status->value,
                'verified_at' => $reference->verified_at,
                'error' => $reference->verification_error,
            ],
        ];
    }
}
