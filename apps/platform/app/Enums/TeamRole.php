<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Contributor = 'contributor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::Contributor => 'Contributor',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Full access, including closing the team.',
            self::Admin => 'Full access except closing the team.',
            self::Contributor => 'Can manage credentials without changing applications.',
            self::Viewer => 'Read-only access to team resources.',
        };
    }

    public function canBeAssigned(): bool
    {
        return $this !== self::Owner;
    }

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
