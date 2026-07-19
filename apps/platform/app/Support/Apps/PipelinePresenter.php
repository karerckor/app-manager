<?php

namespace App\Support\Apps;

use App\Models\CiCdPipeline;
use App\Models\Team;

class PipelinePresenter
{
    public static function present(Team $team, CiCdPipeline $pipeline): array
    {
        return [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'provider' => $pipeline->integration->provider->value,
            'repository' => $pipeline->repository_full_name,
            'workflow' => $pipeline->workflow_name,
            'default_ref' => $pipeline->default_ref,
            'profile_type' => $pipeline->profile_type->value,
            'reporting_mode' => $pipeline->reporting_mode->value,
            'input_schema' => $pipeline->input_schema ?? [],
            'input_defaults' => $pipeline->input_defaults ?? [],
            'run_url' => route('accounts.integrations.pipelines.runs.store', [$team, $pipeline->integration, $pipeline]),
        ];
    }
}
