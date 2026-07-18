<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Contributor = 'contributor';
    case Viewer = 'viewer';

    /**
     * @return list<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Admin => [
                TeamPermission::View,
                TeamPermission::ManageCredentials,
                TeamPermission::ManageApplications,
                TeamPermission::ManageTeam,
                TeamPermission::ManageIntegrations,
                TeamPermission::RunPipelines,
            ],
            self::Contributor => [
                TeamPermission::View,
                TeamPermission::ManageCredentials,
            ],
            self::Viewer => [TeamPermission::View],
        };
    }

    public function allows(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
