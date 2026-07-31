<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileAppResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'tags' => $this->tags ?? [],
            'description' => $this->description,
            'metadata' => $this->metadata,
            'assets' => [
                'icon' => $this->asset('icon', 'app_icon'),
                'splashscreen' => $this->asset('splashscreen', 'splash_icon'),
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function asset(string $asset, string $collection): ?array
    {
        $media = $this->resource->getFirstMedia($collection);

        if (! $media) {
            return null;
        }

        return [
            'name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'url' => route('api.v1.apps.assets.show', [
                'team' => $this->team,
                'mobileApp' => $this->resource,
                'asset' => $asset,
            ]),
        ];
    }
}
