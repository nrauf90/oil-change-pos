<?php

namespace Tests\Feature\Tenancy;

use Tests\TestCase;

/**
 * The containment root that tenant SQLite targets are validated against must
 * not contain the central control-plane database.
 *
 * Every other tenancy test overrides `database.tenant_sqlite_root` to a
 * temporary directory, so none of them exercises the shipped default — which
 * is how it came to be widened to `database_path()` without a failing test.
 * This one reads `config/database.php` itself for that reason.
 */
class TenantSqliteRootDefaultTest extends TestCase
{
    public function test_the_shipped_tenant_sqlite_root_excludes_the_central_database(): void
    {
        $shipped = require base_path('config/database.php');

        $root = $this->normalise((string) $shipped['tenant_sqlite_root']);

        $this->assertSame(
            $this->normalise(database_path('tenants')),
            $root,
            'the shipped containment root should be database/tenants, not the whole database directory',
        );

        $this->assertFalse(
            str_starts_with($this->normalise(database_path('database.sqlite')), rtrim($root, '/').'/'),
            'the central control-plane database sits inside the tenant containment root',
        );
    }

    private function normalise(string $path): string
    {
        $resolved = realpath($path);

        return rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $resolved === false ? $path : $resolved), '/');
    }
}
