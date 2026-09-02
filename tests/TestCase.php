<?php

namespace Tests;

use App\Models\Central\Shop;
use App\Tenancy\TenantConnectionManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\UsesTenantDatabases;

abstract class TestCase extends BaseTestCase
{
    use UsesTenantDatabases;

    private static ?string $testDatabaseRoot = null;

    private static bool $centralDatabaseMigrated = false;

    private ?string $defaultTestTenantDatabase = null;

    protected function setUpTraits()
    {
        if ($this->usesDefaultTenantContext()) {
            $this->initializeDefaultTenantDatabases();
        }

        $uses = parent::setUpTraits();

        if ($this->usesDefaultTenantContext()) {
            if (isset($uses[LazilyRefreshDatabase::class])) {
                DB::connection((string) config('database.default'))->select('select 1');
            }

            $this->aliasDefaultConnectionToTenant();
            $this->beforeApplicationDestroyed(function (): void {
                $this->cleanUpDefaultTenantDatabases();
            });
        }

        return $uses;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests assert on rendered Blade, not on the asset pipeline.
        $this->withoutVite();
    }

    protected function usesDefaultTenantContext(): bool
    {
        return true;
    }

    private function initializeDefaultTenantDatabases(): void
    {
        $databaseRoot = self::databaseRootForTests();
        $tenantRoot = $databaseRoot.DIRECTORY_SEPARATOR.'tenants';
        (new Filesystem)->ensureDirectoryExists($tenantRoot, 0700);

        config()->set('database.tenant_sqlite_root', $tenantRoot);
        config()->set(
            'database.tenant_attestation_lock_path',
            $databaseRoot.DIRECTORY_SEPARATOR.'attestation-locks',
        );

        $centralDatabase = $databaseRoot.DIRECTORY_SEPARATOR.'central.sqlite';

        if (! is_file($centralDatabase) && ! touch($centralDatabase)) {
            throw new RuntimeException('Unable to create the central test database.');
        }

        $this->configureCentralDatabase($centralDatabase);

        if (! self::$centralDatabaseMigrated) {
            $this->migrateCentralDatabase();
            self::$centralDatabaseMigrated = true;
        }

        $tenantId = (string) Str::uuid();
        $this->defaultTestTenantDatabase = $tenantRoot.DIRECTORY_SEPARATOR.$tenantId.'.sqlite';
        $shop = Shop::registerForProvisioning(
            name: 'Test Tenant '.$tenantId,
            slug: 'test-tenant-'.$tenantId,
            databaseDriver: 'sqlite',
            databaseName: $this->defaultTestTenantDatabase,
        );
        $this->createMigratedTenantDatabase($shop);
        app(TenantConnectionManager::class)->connect($shop);
    }

    private function aliasDefaultConnectionToTenant(): void
    {
        $defaultConnection = DB::connection((string) config('database.default'));
        $tenantConnection = DB::connection('tenant');
        $transactionLevel = $defaultConnection->transactionLevel();

        while ($defaultConnection->transactionLevel() > 0) {
            $defaultConnection->rollBack();
        }

        $defaultConnection->setPdo($tenantConnection->getPdo());
        $defaultConnection->setReadPdo($tenantConnection->getReadPdo());

        for ($level = 0; $level < $transactionLevel; $level++) {
            $defaultConnection->beginTransaction();
        }

        if ($this->app->bound('db.transactions')) {
            $tenantConnection->setTransactionManager($this->app->make('db.transactions'));
        }

        $synchronizeTransactionLevel = function (int $transactionLevel): void {
            $this->transactions = $transactionLevel;
        };
        $synchronizeTransactionLevel->call($tenantConnection, $defaultConnection->transactionLevel());
    }

    private function cleanUpDefaultTenantDatabases(): void
    {
        $tenantDatabase = $this->defaultTestTenantDatabase;
        $this->defaultTestTenantDatabase = null;

        try {
            app(TenantConnectionManager::class)->disconnect();
            DB::purge((string) config('database.default'));
            DB::purge('central');
        } finally {
            if ($tenantDatabase !== null) {
                (new Filesystem)->delete([
                    $tenantDatabase,
                    $tenantDatabase.'-shm',
                    $tenantDatabase.'-wal',
                ]);
            }
        }
    }

    private static function databaseRootForTests(): string
    {
        if (self::$testDatabaseRoot !== null) {
            return self::$testDatabaseRoot;
        }

        $databaseRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oil-change-pos-tests-'.Str::uuid();
        (new Filesystem)->ensureDirectoryExists($databaseRoot, 0700);
        self::$testDatabaseRoot = $databaseRoot;

        register_shutdown_function(static function () use ($databaseRoot): void {
            (new Filesystem)->deleteDirectory($databaseRoot);
        });

        return $databaseRoot;
    }
}
