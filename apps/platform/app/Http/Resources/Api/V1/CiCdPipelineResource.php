<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CiCdPipelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'profile_type' => $this->profile_type->value,
            'reporting_mode' => $this->reporting_mode->value,
            'repository_full_name' => $this->repository_full_name,
            'workflow_id' => $this->workflow_id,
            'workflow_name' => $this->workflow_name,
            'workflow_path' => $this->workflow_path,
            'default_ref' => $this->default_ref,
            'input_defaults' => $this->input_defaults ?? [],
            'mobile_app_id' => $this->mobile_app_id,
            'app_identifier_id' => $this->app_identifier_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
