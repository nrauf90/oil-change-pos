<?php

namespace Tests\Concerns;

use App\Models\Central\Shop;
use App\Tenancy\SqliteDatabaseIdentitySnapshot;
use App\Tenancy\TenantConnectionManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

trait UsesTenantDatabases
{
    private const TENANT_INSTALLATION_MIGRATION = '2026_09_02_042731_create_tenant_installations_table.php';

    private const TENANT_INSTALLER_CONNECTION = 'tenant_installer';

    private static ?string $migratedTenantDatabaseTemplate = null;

    private static ?string $migratedTenantDatabaseTemplateRoot = null;

    protected function configureCentralDatabase(string $database): void
    {
        config()->set('database.connections.central', $this->sqliteTestConnectionConfiguration($database));
        DB::purge('central');
    }

    protected function migrateCentralDatabase(): void
    {
        $exitCode = Artisan::call('migrate', [
            '--database' => 'central',
            '--path' => $this->centralMigrationPath(),
            '--realpath' => true,
            '--no-interaction' => true,
        ]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Central test database migrations failed.');
        }
    }

    protected function createTenantDatabase(Shop $shop): string
    {
        return $this->materializeTenantDatabase($shop);
    }

    protected function createMigratedTenantDatabase(Shop $shop): string
    {
        $this->prepareMigratedTenantDatabaseTemplate();
        $templateDatabase = self::$migratedTenantDatabaseTemplate;

        if ($templateDatabase === null) {
            throw new RuntimeException('The migrated tenant database template is unavailable.');
        }

        return $this->materializeTenantDatabase($shop, $templateDatabase);
    }

    protected function prepareMigratedTenantDatabaseTemplate(): void
    {
        if (self::$migratedTenantDatabaseTemplate !== null
            && is_file(self::$migratedTenantDatabaseTemplate)) {
            return;
        }

        $tenantRoot = config('database.tenant_sqlite_root');

        if (! is_string($tenantRoot) || $tenantRoot === '') {
            throw new RuntimeException('The tenant SQLite test root is not configured.');
        }

        $templateId = (string) Str::uuid();
        $sourceDatabase = $tenantRoot.DIRECTORY_SEPARATOR.'template-'.$templateId.'.sqlite';
        $shop = Shop::registerForProvisioning(
            name: 'Tenant migration template '.$templateId,
            slug: 'tenant-migration-template-'.$templateId,
            databaseDriver: 'sqlite',
            databaseName: $sourceDatabase,
        );
        $this->createTenantDatabase($shop);

        try {
            $this->migrateTenantDatabase($shop);
            $templateDatabase = self::migratedTenantDatabaseTemplateRoot()
                .DIRECTORY_SEPARATOR.'tenant.sqlite';

            if (! (new Filesystem)->copy($sourceDatabase, $templateDatabase)) {
                throw new RuntimeException('Unable to copy the migrated tenant database template.');
            }

            self::$migratedTenantDatabaseTemplate = $templateDatabase;
        } finally {
            app(TenantConnectionManager::class)->disconnect();
            (new Filesystem)->delete([
                $sourceDatabase,
                $sourceDatabase.'-shm',
                $sourceDatabase.'-wal',
            ]);
        }
    }

    private function materializeTenantDatabase(Shop $shop, ?string $templateDatabase = null): string
    {
        $database = (string) $shop->database_name;
        $handle = @fopen($database, 'x+b');

        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to create a tenant test database exclusively.');
        }

        try {
            if ($templateDatabase !== null) {
                $this->copyTenantDatabaseTemplate($templateDatabase, $handle);
            }

            $identity = SqliteDatabaseIdentitySnapshot::capture($database, $handle);
            fclose($handle);
            $handle = null;

            $shop->materializeSqliteDatabaseIdentityAfterCreation($identity);

            if ($templateDatabase === null) {
                $this->installTenantDatabaseMarker($database, $shop);
            } else {
                $this->replaceTenantDatabaseMarker($database, $shop);
            }
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            (new Filesystem)->delete([$database, $database.'-shm', $database.'-wal']);

            throw $exception;
        }

        return $database;
    }

    protected function migrateTenantDatabase(Shop $shop): void
    {
        app(TenantConnectionManager::class)->within($shop, function (): void {
            $exitCode = Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => $this->tenantMigrationPath(),
                '--realpath' => true,
                '--no-interaction' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException('Tenant test database migrations failed.');
            }
        });
    }

    /** @return array<string, mixed> */
    protected function sqliteTestConnectionConfiguration(string $database): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'transaction_mode' => 'DEFERRED',
        ];
    }

    protected function centralMigrationPath(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'
            .DIRECTORY_SEPARATOR.'central';
    }

    protected function tenantMigrationPath(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'
            .DIRECTORY_SEPARATOR.'tenant';
    }

    protected function installTenantDatabaseMarker(string $database, Shop $shop): void
    {
        config()->set(
            'database.connections.'.self::TENANT_INSTALLER_CONNECTION,
            $this->sqliteTestConnectionConfiguration($database),
        );
        DB::purge(self::TENANT_INSTALLER_CONNECTION);

        try {
            $exitCode = Artisan::call('migrate', [
                '--database' => self::TENANT_INSTALLER_CONNECTION,
                '--path' => $this->tenantMigrationPath().DIRECTORY_SEPARATOR.self::TENANT_INSTALLATION_MIGRATION,
                '--realpath' => true,
                '--no-interaction' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException('Tenant installation marker migration failed.');
            }

            $connection = DB::connection(self::TENANT_INSTALLER_CONNECTION);
            $connection->table('tenant_installations')->insert([
                'id' => 1,
                'shop_id' => $shop->getKey(),
                'target_fingerprint' => $shop->database_target_fingerprint,
                'attestation_hmac' => $shop->databaseAttestationHmac(),
                'connection_nonce' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            DB::purge(self::TENANT_INSTALLER_CONNECTION);
            $connections = config('database.connections');

            if (is_array($connections)) {
                unset($connections[self::TENANT_INSTALLER_CONNECTION]);
                config()->set('database.connections', $connections);
            }
        }
    }

    /** @param resource $destination */
    private function copyTenantDatabaseTemplate(string $templateDatabase, mixed $destination): void
    {
        $source = @fopen($templateDatabase, 'rb');

        if (! is_resource($source)) {
            throw new RuntimeException('Unable to open the migrated tenant database template.');
        }

        try {
            $sourceSize = filesize($templateDatabase);
            $copiedBytes = stream_copy_to_stream($source, $destination);

            if (! is_int($sourceSize) || $copiedBytes !== $sourceSize || ! fflush($destination)) {
                throw new RuntimeException('Unable to materialize the migrated tenant database template.');
            }
        } finally {
            fclose($source);
        }
    }

    protected function replaceTenantDatabaseMarker(string $database, Shop $shop): void
    {
        config()->set(
            'database.connections.'.self::TENANT_INSTALLER_CONNECTION,
            $this->sqliteTestConnectionConfiguration($database),
        );
        DB::purge(self::TENANT_INSTALLER_CONNECTION);

        try {
            $updated = DB::connection(self::TENANT_INSTALLER_CONNECTION)
                ->table('tenant_installations')
                ->where('id', 1)
                ->update([
                    'shop_id' => $shop->getKey(),
                    'target_fingerprint' => $shop->database_target_fingerprint,
                    'attestation_hmac' => $shop->databaseAttestationHmac(),
                    'connection_nonce' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new RuntimeException('Unable to bind the tenant database template marker.');
            }
        } finally {
            DB::purge(self::TENANT_INSTALLER_CONNECTION);
            $connections = config('database.connections');

            if (is_array($connections)) {
                unset($connections[self::TENANT_INSTALLER_CONNECTION]);
                config()->set('database.connections', $connections);
            }
        }
    }

    private static function migratedTenantDatabaseTemplateRoot(): string
    {
        if (self::$migratedTenantDatabaseTemplateRoot !== null) {
            return self::$migratedTenantDatabaseTemplateRoot;
        }

        $templateRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oil-change-pos-tenant-template-'.Str::uuid();
        (new Filesystem)->ensureDirectoryExists($templateRoot, 0700);
        self::$migratedTenantDatabaseTemplateRoot = $templateRoot;

        register_shutdown_function(static function () use ($templateRoot): void {
            (new Filesystem)->deleteDirectory($templateRoot);
        });

        return $templateRoot;
    }
}
