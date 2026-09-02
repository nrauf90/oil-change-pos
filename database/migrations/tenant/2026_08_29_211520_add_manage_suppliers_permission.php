<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION = 'suppliers.manage';

    public function up(): void
    {
        $database = DB::connection('tenant');
        $now = now();

        $database->table('permissions')->insertOrIgnore([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->table('roles')->insertOrIgnore([
            'name' => 'admin',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = $database->table('permissions')
            ->where('name', self::PERMISSION)
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
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->delete();
    }
};
