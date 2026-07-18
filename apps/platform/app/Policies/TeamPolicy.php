<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $team->allows($user, TeamPermission::View);
    }

    public function manageIntegrations(User $user, Team $team): bool
    {
        return $team->allows($user, TeamPermission::ManageIntegrations);
    }

    public function runPipelines(User $user, Team $team): bool
    {
        return $team->allows($user, TeamPermission::RunPipelines);
    }
}
