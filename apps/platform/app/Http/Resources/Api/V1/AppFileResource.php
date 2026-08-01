<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** @mixin Media */
class AppFileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $team = $request->route('team');
        $app = $request->route('mobileApp');

        return [
            'id' => $this->uuid,
            'name' => $this->file_name,
            'extension' => strtolower(pathinfo($this->file_name, PATHINFO_EXTENSION)),
            'mime_type' => $this->mime_type ?: 'application/octet-stream',
            'size' => $this->size,
            'metadata' => $this->getCustomProperty('metadata'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'urls' => [
                'self' => route('api.v1.apps.files.show', [$team, $app, $this->file_name]),
                'content' => route('api.v1.apps.files.content.show', [$team, $app, $this->file_name]),
            ],
        ];
    }
}
