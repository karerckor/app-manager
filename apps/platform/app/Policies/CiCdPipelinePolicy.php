<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\CiCdPipeline;
use App\Models\User;

class CiCdPipelinePolicy
{
    public function view(User $user, CiCdPipeline $pipeline): bool
    {
        return $pipeline->team->allows($user, TeamPermission::ManageIntegrations);
    }

    public function update(User $user, CiCdPipeline $pipeline): bool
    {
        return $this->view($user, $pipeline);
    }

    public function delete(User $user, CiCdPipeline $pipeline): bool
    {
        return $this->view($user, $pipeline);
    }

    public function run(User $user, CiCdPipeline $pipeline): bool
    {
        return $pipeline->team->allows($user, TeamPermission::RunPipelines);
    }
}
