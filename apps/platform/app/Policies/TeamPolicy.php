<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $this->allows($user, $team, TeamPermission::View);
    }

    public function manageCredentials(User $user, Team $team): bool
    {
        return $this->allows($user, $team, TeamPermission::ManageCredentials);
    }

    public function manageApplications(User $user, Team $team): bool
    {
        return $this->allows($user, $team, TeamPermission::ManageApplications);
    }

    public function manageTeam(User $user, Team $team): bool
    {
        return $this->allows($user, $team, TeamPermission::ManageTeam);
    }

    public function delete(User $user, Team $team): bool
    {
        return $team->owner_id === $user->id
            && $this->allows($user, $team, TeamPermission::CloseTeam);
    }

    private function allows(User $user, Team $team, TeamPermission $permission): bool
    {
        return $user->hasTeamPermission($team, $permission);
    }
}
