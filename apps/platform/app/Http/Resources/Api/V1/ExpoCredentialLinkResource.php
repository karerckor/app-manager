<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpoCredentialLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expo_project_id' => $this->expo_project_id,
            'credential_id' => $this->credential_id,
            'app_identifier_id' => $this->app_identifier_id,
            'remote_resource_type' => $this->remote_resource_type,
            'remote_resource_id' => $this->remote_resource_id,
            'metadata' => $this->metadata ?? [],
            'last_synced_at' => $this->last_synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
