<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'current_team_id'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, TwoFactorAuthenticatable;

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function teamRole(Team $team): ?TeamRole
    {
        return $this->withinPermissionTeam($team, function () {
            $role = $this->getRoleNames()->first();

            return is_string($role) ? TeamRole::tryFrom($role) : null;
        });
    }

    public function hasTeamPermission(Team $team, TeamPermission $permission): bool
    {
        if (! $this->teams()->whereKey($team->id)->exists()) {
            return false;
        }

        return $this->withinPermissionTeam(
            $team,
            fn () => $this->hasPermissionTo($permission->value),
        );
    }

    public function syncTeamRole(Team $team, TeamRole $role): void
    {
        $this->withinPermissionTeam($team, fn () => $this->syncRoles([$role->value]));
    }

    public function removeTeamRoles(Team $team): void
    {
        $this->withinPermissionTeam($team, fn () => $this->syncRoles([]));
    }

    private function withinPermissionTeam(Team $team, callable $callback): mixed
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($team->id);
            $this->unsetRelation('roles')->unsetRelation('permissions');

            return $callback();
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
            $this->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
