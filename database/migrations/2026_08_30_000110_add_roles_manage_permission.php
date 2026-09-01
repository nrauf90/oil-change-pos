<?php

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings an already-migrated database in step with the enums, which have gained
 * `roles.manage` — the key to the new Role Permissions screen. It is deliberately
 * separate from `modules.manage` so granting the module switchboard does not also
 * grant the power to rewrite the permission matrix.
 *
 * Idempotent: it creates the permission if it is missing and grants it if it is
 * missing, so re-running it is a no-op. Permissions the enum no longer declares
 * are dropped, which keeps the enum the single source of truth for the catalogue.
 *
 * Unlike {@see 2026_08_28_000102_resync_roles_and_permissions.php} this does NOT
 * `syncPermissions()` each role back to the enum defaults, and it touches only the
 * one permission this release adds. From now on the owner can edit the matrix from
 * the back office, so a blanket resync on deploy would silently undo their work —
 * even an additive one, which would hand back every default they had deliberately
 * taken away. Restoring the shipped matrix is a button on that screen, not a side
 * effect of upgrading.
 */
return new class extends Migration
{
    /** The one permission this release introduces. */
    private const ADDED = 'roles.manage';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        Permission::query()->whereNotIn('name', PermissionEnum::values())->delete();

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value, 'web');

            $wanted = in_array(self::ADDED, $roleEnum->permissionNames(), true);
            $has = $role->permissions->pluck('name')->contains(self::ADDED);

            if ($wanted && ! $has) {
                $role->givePermissionTo(self::ADDED);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->where('name', self::ADDED)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
