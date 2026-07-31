<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpoProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'integration_id' => $this->integration_id,
            'mobile_app' => $this->whenLoaded('mobileApp', fn () => $this->mobileApp ? [
                'id' => $this->mobileApp->id,
                'slug' => $this->mobileApp->slug,
                'name' => $this->mobileApp->name,
            ] : null),
            'external_id' => $this->external_id,
            'account' => [
                'id' => $this->account_id,
                'name' => $this->account_name,
            ],
            'slug' => $this->slug,
            'name' => $this->name,
            'full_name' => $this->full_name,
            'description' => $this->description,
            'icon_url' => $this->icon_url,
            'sync_metadata' => $this->sync_metadata,
            'exists_on_expo' => $this->exists_on_expo,
            'last_synced_at' => $this->last_synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
