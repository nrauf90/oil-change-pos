<?php

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Re-syncs the roles/permissions tables from the enums.
 *
 * A fresh database gets its matrix from the original seeding migration, but a
 * shop that is already running has long since passed that point — so every time
 * a permission is added to the enum, an already-migrated database needs to be
 * brought back in step. This run adds `modules.manage`, which splits the module
 * switchboard away from `logs.view`.
 *
 * Idempotent: it creates what is missing and syncs each role to the enum, so
 * re-running it is a no-op. Permissions the enum no longer declares are dropped,
 * which keeps the enum the single source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        Permission::query()->whereNotIn('name', PermissionEnum::values())->delete();

        foreach (RoleEnum::cases() as $roleEnum) {
            Role::findOrCreate($roleEnum->value, 'web')
                ->syncPermissions($roleEnum->permissionNames());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Nothing to undo: this migration only restores the matrix the enums
        // describe. Rolling it back would leave the database out of step.
    }
};
