<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event?->event_type?->value,
            'status' => $this->status->value,
            'attempt_count' => $this->attempt_count,
            'response_status' => $this->response_status,
            'duration_ms' => $this->duration_ms,
            'last_error' => $this->last_error,
            'redelivery_of_id' => $this->redelivery_of_id,
            'next_attempt_at' => $this->next_attempt_at,
            'delivered_at' => $this->delivered_at,
            'response_preview' => $this->response_body === null ? null : mb_substr($this->response_body, 0, 512),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
