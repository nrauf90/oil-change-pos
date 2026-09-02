<?php

namespace App\Actions\Tenancy;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\ModuleSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final readonly class SyncTenantAuthorization
{
    public function __construct(
        private PermissionRegistrar $permissionRegistrar,
        private ModuleRegistry $moduleRegistry,
    ) {}

    public function handle(User $owner): void
    {
        DB::connection('tenant')->transaction(function () use ($owner): void {
            $this->permissionRegistrar->forgetCachedPermissions();

            foreach (PermissionEnum::cases() as $permission) {
                Permission::query()->firstOrCreate([
                    'name' => $permission->value,
                    'guard_name' => 'web',
                ]);
            }

            foreach (RoleEnum::cases() as $role) {
                $roleModel = Role::query()->firstOrCreate([
                    'name' => $role->value,
                    'guard_name' => 'web',
                ]);
                $roleModel->syncPermissions($role->permissionNames());
            }

            foreach ($this->moduleRegistry->all() as $module) {
                ModuleSetting::query()->firstOrCreate(
                    ['key' => $module->key()],
                    ['enabled' => $module->isCore() || $module->enabledByDefault()],
                );
            }

            $owner->assignRoleEnum(RoleEnum::Admin);
            $this->moduleRegistry->flush();
            $this->permissionRegistrar->forgetCachedPermissions();
        });
    }
}
