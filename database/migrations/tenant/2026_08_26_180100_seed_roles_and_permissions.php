<?php

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the fixed role/permission matrix as part of the schema.
 *
 * It lives in a migration rather than a seeder so a freshly migrated database —
 * including every RefreshDatabase test run — always has the three roles ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (RoleEnum::cases() as $roleEnum) {
            Role::findOrCreate($roleEnum->value, 'web')
                ->syncPermissions($roleEnum->permissionNames());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->whereIn('name', RoleEnum::values())->delete();
        Permission::query()->whereIn('name', PermissionEnum::values())->delete();
    }
};
