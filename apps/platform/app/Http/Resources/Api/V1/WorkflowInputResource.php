<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowInputResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $definition = $this->resource['definition'];

        return [
            'name' => $this->resource['name'],
            'type' => $definition['type'] ?? 'string',
            'required' => (bool) ($definition['required'] ?? false),
            'default' => $definition['default'] ?? null,
            'options' => $definition['options'] ?? [],
            'description' => $definition['description'] ?? null,
        ];
    }
}
