<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ADDED_PERMISSION = 'roles.manage';

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
        'modules.manage',
        'roles.manage',
        'suppliers.manage',
        'logs.view',
    ];

    private const ROLES = [
        'admin',
        'manager',
        'technician',
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
        $database->table('permissions')->whereNotIn('name', self::PERMISSIONS)->delete();

        $database->table('roles')->insertOrIgnore(array_map(
            static fn (string $role): array => [
                'name' => $role,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::ROLES,
        ));

        $permissionId = $database->table('permissions')
            ->where('name', self::ADDED_PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');
        $roleId = $database->table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->value('id');

        $database->table('role_has_permissions')->insertOrIgnore([
            'permission_id' => $permissionId,
            'role_id' => $roleId,
        ]);
    }

    public function down(): void
    {
        DB::connection('tenant')
            ->table('permissions')
            ->where('name', self::ADDED_PERMISSION)
            ->where('guard_name', 'web')
            ->delete();
    }
};
