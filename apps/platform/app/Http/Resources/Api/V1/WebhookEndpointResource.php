<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\IntegrationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookEndpointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->integration?->name,
            'url' => $this->url,
            'secret_hint' => $this->secret_hint,
            'subscribed_events' => $this->subscribed_events,
            'enabled' => $this->integration?->status === IntegrationStatus::Active,
            'consecutive_failures' => $this->consecutive_failures,
            'last_delivered_at' => $this->last_delivered_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
