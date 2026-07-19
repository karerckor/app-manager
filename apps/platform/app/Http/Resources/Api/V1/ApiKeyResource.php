<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scopes' => $this->abilities ?? [],
            'created_by' => $this->tokenable?->only('id', 'name', 'email'),
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
        ];
    }
}
