<?php

namespace Tests\Feature\Tenancy;

use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TenantMigrationHistoryTest extends TestCase
{
    private const HISTORICAL_DATA_MIGRATIONS = [
        '2026_08_26_180100_seed_roles_and_permissions.php',
        '2026_08_28_000102_resync_roles_and_permissions.php',
        '2026_08_29_000101_add_measured_stock_to_items_table.php',
        '2026_08_29_211520_add_manage_suppliers_permission.php',
        '2026_08_30_000110_add_roles_manage_permission.php',
        '2026_09_01_135124_add_is_universal_to_items_table.php',
    ];

    private string $databaseRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tenant-history-'.Str::uuid();
        $tenantRoot = $this->databaseRoot.DIRECTORY_SEPARATOR.'tenants';
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists($tenantRoot, 0700);

        $centralDatabase = $this->databaseRoot.DIRECTORY_SEPARATOR.'central.sqlite';
        $tenantDatabase = $tenantRoot.DIRECTORY_SEPARATOR.'tenant.sqlite';
        $this->createDatabaseFile($centralDatabase);
        $this->createDatabaseFile($tenantDatabase);

        config()->set('database.default', 'central');
        config()->set('database.tenant_sqlite_root', $tenantRoot);
        config()->set(
            'database.tenant_attestation_lock_path',
            $this->databaseRoot.DIRECTORY_SEPARATOR.'attestation-locks',
        );
        $this->configureCentralDatabase($centralDatabase);
        config()->set('database.connections.tenant', $this->sqliteTestConnectionConfiguration($tenantDatabase));
        Event::forget(ConnectionEstablished::class);
        DB::purge('tenant');
        $this->migrateCentralDatabase();
    }

    protected function tearDown(): void
    {
        try {
            app(TenantConnectionManager::class)->disconnect();
            DB::purge('tenant');
            DB::purge('central');
        } finally {
            (new Filesystem)->deleteDirectory($this->databaseRoot);
            parent::tearDown();
        }
    }

    public function test_tenant_history_runs_without_manager_activation_while_default_remains_central(): void
    {
        $this->assertSame('central', config('database.default'));
        $this->assertSame(DB::connection('central')->getPdo(), DB::connection()->getPdo());
        $this->assertFalse(app(TenantContext::class)->initialized());

        $exitCode = Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => $this->tenantMigrationPath(),
            '--realpath' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue(Schema::connection('tenant')->hasTable('users'));
        $this->assertTrue(Schema::connection('tenant')->hasTable('items'));
        // 36 after `reports.view_financials` was removed: it was granted and
        // seeded while being read by no code, so unticking it revoked nothing.
        $this->assertSame(36, DB::connection('tenant')->table('permissions')->count());
        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertSame('central', config('database.default'));
    }

    public function test_tenant_migration_history_has_no_current_model_or_ambient_database_dependencies(): void
    {
        $migrations = glob($this->tenantMigrationPath().DIRECTORY_SEPARATOR.'*.php');

        $this->assertIsArray($migrations);
        $this->assertNotEmpty($migrations);

        foreach ($migrations as $migration) {
            $contents = file_get_contents($migration);
            $filename = basename($migration);

            $this->assertIsString($contents);
            $this->assertStringNotContainsString('use App\\Models\\', $contents, $filename);
            $this->assertStringNotContainsString('use App\\Enums\\', $contents, $filename);
            $this->assertDoesNotMatchRegularExpression('/\\bDB::(?!connection\\()/', $contents, $filename);
        }
    }

    public function test_historical_data_migrations_use_explicit_tenant_connections(): void
    {
        foreach (self::HISTORICAL_DATA_MIGRATIONS as $migration) {
            $contents = file_get_contents($this->tenantMigrationPath().DIRECTORY_SEPARATOR.$migration);

            $this->assertIsString($contents);
            $this->assertStringContainsString("connection('tenant')", $contents, $migration);
            $this->assertStringNotContainsString('PermissionRegistrar', $contents, $migration);
            $this->assertStringNotContainsString('Schema::table(', $contents, $migration);
        }
    }

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    private function createDatabaseFile(string $database): void
    {
        $handle = @fopen($database, 'x+b');

        if (! is_resource($handle)) {
            throw new RuntimeException("Unable to create test database [{$database}].");
        }

        fclose($handle);
    }

    /**
     * The migration that removes `reports.view_financials` lands after the
     * `tenant_installations` marker, so during adoption it runs against a
     * customer's legacy production database in the window between the DDL and
     * the migration-log write. A crash there re-runs it on retry, so it has to
     * be re-runnable or the shop becomes permanently unadoptable.
     */
    public function test_dropping_the_financials_permission_is_re_runnable(): void
    {
        $exitCode = Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => $this->tenantMigrationPath(),
            '--realpath' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());

        $migration = (new Filesystem)->glob($this->tenantMigrationPath().DIRECTORY_SEPARATOR.'*drop_reports_view_financials_permission.php');
        $this->assertCount(1, $migration, 'the migration under test is missing');

        $before = DB::connection('tenant')->table('permissions')->count();

        // Simulate the crash: the work landed, the log write did not.
        DB::connection('tenant')->table('migrations')
            ->where('migration', pathinfo($migration[0], PATHINFO_FILENAME))
            ->delete();

        $retry = Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => $this->tenantMigrationPath(),
            '--realpath' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $retry, 'the migration is not re-runnable: '.Artisan::output());
        $this->assertSame($before, DB::connection('tenant')->table('permissions')->count());
        $this->assertSame(0, DB::connection('tenant')->table('permissions')
            ->where('name', 'reports.view_financials')->count());
    }
}
