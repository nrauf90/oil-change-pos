<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'pos.use',
        'sales.view_any',
        'sales.view',
        'sales.create',
        'sales.delete',
        'sales.export_pdf',
        'pricing.view',
        'items.view_any',
        'items.create',
        'items.quick_create',
        'items.update',
        'items.delete',
        'items.view_unit_cost',
        'items.set_unit_cost',
        'items.view_stock',
        'items.manage_stock',
        'reports.view_dashboard',
        'reports.view_financials',
        'reports.view_margins',
        'expenses.view_any',
        'expenses.create',
        'expenses.update',
        'expenses.delete',
        'expenses.view_cash_drawer',
        'users.view_any',
        'users.create',
        'users.update',
        'users.delete',
        'scripts.view',
        'service_history.lookup',
        'inspections.view_any',
        'inspections.create',
        'inspections.update',
        'logs.view',
    ];

    private const ROLE_PERMISSIONS = [
        'admin' => self::PERMISSIONS,
        'manager' => [
            'pos.use',
            'sales.view_any',
            'sales.view',
            'sales.create',
            'sales.export_pdf',
            'pricing.view',
            'items.view_any',
            'items.create',
            'items.quick_create',
            'items.update',
            'items.view_stock',
            'items.manage_stock',
            'reports.view_dashboard',
            'reports.view_financials',
            'expenses.view_any',
            'expenses.create',
            'expenses.update',
            'expenses.view_cash_drawer',
            'scripts.view',
            'service_history.lookup',
            'inspections.view_any',
            'inspections.create',
            'inspections.update',
        ],
        'technician' => [
            'scripts.view',
            'service_history.lookup',
            'inspections.view_any',
            'inspections.create',
            'inspections.update',
        ],
    ];

    public function up(): void
    {
        $database = DB::connection('tenant');
        $now = now();

        $database->table('permissions')->insertOrIgnore(array_map(
            static fn (string $permission): array => [
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::PERMISSIONS,
        ));

        $database->table('roles')->insertOrIgnore(array_map(
            static fn (string $role): array => [
                'name' => $role,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            array_keys(self::ROLE_PERMISSIONS),
        ));

        $permissionIds = $database->table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('id', 'name');
        $roleIds = $database->table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', array_keys(self::ROLE_PERMISSIONS))
            ->pluck('id', 'name');

        foreach (self::ROLE_PERMISSIONS as $role => $permissions) {
            $roleId = $roleIds->get($role);
            $database->table('role_has_permissions')->where('role_id', $roleId)->delete();
            $database->table('role_has_permissions')->insert(array_map(
                static fn (string $permission): array => [
                    'permission_id' => $permissionIds->get($permission),
                    'role_id' => $roleId,
                ],
                $permissions,
            ));
        }
    }

    public function down(): void
    {
        $database = DB::connection('tenant');

        $database->table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', array_keys(self::ROLE_PERMISSIONS))
            ->delete();
        $database->table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->delete();
    }
};
