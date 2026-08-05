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
            'external_id' => $this->external_id,
            'status' => $this->status->value,
            'profile_type' => $this->profile_type->value,
            'commit_sha' => $this->commit_sha,
            'ci' => [
                'provider' => $this->ci_provider,
                'run_id' => $this->ci_run_id,
                'run_url' => $this->ci_run_url,
            ],
            'artifact_url' => $this->artifact_url,
            'context_schema_version' => $this->context_schema_version,
            'context_snapshot' => $this->context_snapshot,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'last_log_sequence' => $this->logs_max_sequence ?? $this->logs()->max('sequence'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
