<?php

namespace Database\Seeders;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AclSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);

        foreach (TeamPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (TeamRole::cases() as $teamRole) {
            $role = Role::findOrCreate($teamRole->value, 'web');
            $role->syncPermissions(array_map(
                fn (TeamPermission $permission) => $permission->value,
                $teamRole->permissions(),
            ));
        }

        $registrar->forgetCachedPermissions();
    }
}
