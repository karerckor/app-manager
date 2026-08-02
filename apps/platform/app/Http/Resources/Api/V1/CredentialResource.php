<?php

namespace App\Http\Resources\Api\V1;

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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
