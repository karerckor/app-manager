<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BuildRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_uuid' => $this->request_uuid,
            'app_id' => $this->mobile_app_id,
            'app_identifier_id' => $this->app_identifier_id,
            'profile_type' => $this->profile_type->value,
            'status' => $this->status->value,
            'reporting_mode' => $this->reporting_mode->value,
            'context' => $this->context_snapshot,
            'started_at' => $this->started_at,
            'cancel_requested_at' => $this->cancel_requested_at,
            'finished_at' => $this->finished_at,
            'logs_updated_at' => $this->logs_updated_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
