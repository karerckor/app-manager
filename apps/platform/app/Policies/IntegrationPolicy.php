<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Integration;
use App\Models\User;

class IntegrationPolicy
{
    public function view(User $user, Integration $integration): bool
    {
        return $integration->team->allows($user, TeamPermission::ManageIntegrations);
    }

    public function update(User $user, Integration $integration): bool
    {
        return $this->view($user, $integration);
    }

    public function delete(User $user, Integration $integration): bool
    {
        return $this->view($user, $integration);
    }
}
