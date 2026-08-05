<?php

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['team_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->uuid('model_uuid');
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->index(['model_uuid', 'model_type']);
            $table->primary(['team_id', 'permission_id', 'model_uuid', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->uuid('model_uuid');
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->index(['model_uuid', 'model_type']);
            $table->primary(['team_id', 'role_id', 'model_uuid', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->foreignUuid('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'email']);
        });

        $this->seedAcl();
        $this->migrateMembershipRoles();
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }

    private function seedAcl(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function migrateMembershipRoles(): void
    {
        $roleIds = Role::query()
            ->whereNull('team_id')
            ->pluck('id', 'name');

        DB::table('team_user')
            ->whereIn('role', array_map(fn (TeamRole $role) => $role->value, TeamRole::cases()))
            ->orderBy('id')
            ->each(function (object $membership) use ($roleIds) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $roleIds[$membership->role],
                    'model_type' => User::class,
                    'model_uuid' => $membership->user_id,
                    'team_id' => $membership->team_id,
                ]);
            });
    }
};
