<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArtifactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = $this->resource->getFirstMedia('file');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'tags' => $this->tags ?? [],
            'metadata' => $this->metadata,
            'build_record_id' => $this->build_record_id,
            'file' => $media ? [
                'name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'url' => route('api.v1.apps.builds.artifacts.file.show', [
                    'team' => $request->route('team'),
                    'mobileApp' => $request->route('mobileApp'),
                    'buildRecord' => $this->build_record_id,
                    'artifact' => $this->resource,
                ]),
            ] : null,
            'created_at' => $this->created_at,
        ];
    }
}
