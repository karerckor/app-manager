<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlueprintHookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'blueprint_id' => $this->blueprint_id,
            'name' => $this->name,
            'condition' => $this->condition->value,
            'condition_config' => $this->condition_config,
            'actions' => $this->actions ?? [],
            'enabled' => $this->enabled,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
