<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlueprintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'json_schema' => $this->json_schema,
            'required_files' => $this->required_files ?? [],
            'platforms' => $this->platforms ?? [],
            'requires_expo' => $this->requires_expo,
            'applications_count' => $this->when(isset($this->mobile_apps_count), $this->mobile_apps_count),
            'hooks_count' => $this->when(isset($this->hooks_count), $this->hooks_count),
            'hooks' => BlueprintHookResource::collection($this->whenLoaded('hooks')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
