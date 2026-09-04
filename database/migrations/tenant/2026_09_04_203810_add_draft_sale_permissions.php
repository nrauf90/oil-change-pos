<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Draft bills arrive with four permissions.
 *
 * Admins get all four. Managers run the counter, so they may open, amend and
 * complete a bill — but discarding someone's half-finished work stays with the
 * owner, the same line the app already draws around deleting a sale.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'admin' => [
            'draft_sales.view_any',
            'draft_sales.create',
            'draft_sales.complete',
            'draft_sales.delete',
        ],
        'manager' => [
            'draft_sales.view_any',
            'draft_sales.create',
            'draft_sales.complete',
        ],
    ];

    public function up(): void
    {
        $database = DB::connection('tenant');

        // An adopted database may carry a permissions table this application
        // never built. Seeding into a shape we do not recognise would abort the
        // whole adoption over four rows, so leave it to the authorization sync
        // that runs against every tenant instead.
        if (! $this->authorizationTablesAreCanonical()) {
            return;
        }

        $now = now();

        $permissions = collect(self::ROLE_PERMISSIONS)->flatten()->unique();

        foreach ($permissions as $permission) {
            $database->table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::ROLE_PERMISSIONS as $role => $granted) {
            $database->table('roles')->insertOrIgnore([
                'name' => $role,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $roleId = $database->table('roles')
                ->where('name', $role)
                ->where('guard_name', 'web')
                ->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($granted as $permission) {
                $permissionId = $database->table('permissions')
                    ->where('name', $permission)
                    ->where('guard_name', 'web')
                    ->value('id');

                if ($permissionId === null) {
                    continue;
                }

                $database->table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    private function authorizationTablesAreCanonical(): bool
    {
        $schema = Schema::connection('tenant');

        return $schema->hasTable('permissions')
            && $schema->hasTable('roles')
            && $schema->hasTable('role_has_permissions')
            && $schema->hasColumns('permissions', ['name', 'guard_name'])
            && $schema->hasColumns('roles', ['name', 'guard_name']);
    }

    public function down(): void
    {
        if (! $this->authorizationTablesAreCanonical()) {
            return;
        }

        DB::connection('tenant')
            ->table('permissions')
            ->whereIn('name', collect(self::ROLE_PERMISSIONS)->flatten()->unique()->all())
            ->where('guard_name', 'web')
            ->delete();
    }
};
