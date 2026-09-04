<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `reports.view_financials` was granted to Manager, seeded by three
 * migrations and offered as a tickbox in the role matrix, but no code ever
 * read it. An owner who unticked it believing they had revoked a Manager's
 * access to financial figures changed nothing: /reports gates on
 * `reports.view_dashboard` and the drawer on `expenses.view_cash_drawer`.
 *
 * A checkbox that silently promises nothing is worse than an absent one, so
 * the permission is removed rather than wired up to a new meaning.
 */
return new class extends Migration
{
    private const PERMISSION = 'reports.view_financials';

    public function up(): void
    {
        $database = DB::connection('tenant');

        $permissionId = $database->table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        $database->table('role_has_permissions')->where('permission_id', $permissionId)->delete();
        $database->table('model_has_permissions')->where('permission_id', $permissionId)->delete();
        $database->table('permissions')->where('id', $permissionId)->delete();
    }

    /**
     * Restores the row so a rollback lands on the schema the previous
     * migration described. It is still read by nothing.
     */
    public function down(): void
    {
        $database = DB::connection('tenant');
        $now = now();

        $database->table('permissions')->insertOrIgnore([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = $database->table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        $roleId = $database->table('roles')
            ->whereIn('name', ['admin', 'manager'])
            ->where('guard_name', 'web')
            ->pluck('id');

        foreach ($roleId as $id) {
            $database->table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $id,
            ]);
        }
    }
};
