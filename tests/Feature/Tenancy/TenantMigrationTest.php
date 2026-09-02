<?php

namespace Tests\Feature\Tenancy;

use App\Models\Central\Shop;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantMigrationTest extends TestCase
{
    public function test_central_migrations_create_only_central_tables(): void
    {
        $schema = Schema::connection('central');

        $this->assertTrue($schema->hasTable('platform_users'));
        $this->assertTrue($schema->hasTable('shops'));
        $this->assertTrue($schema->hasTable('shop_database_target_claims'));
        $this->assertFalse($schema->hasTable('tenant_installations'));
        $this->assertFalse($schema->hasTable('users'));
        $this->assertFalse($schema->hasTable('items'));
        $this->assertFalse($schema->hasTable('sales'));

        foreach ($this->sharedInfrastructureTables() as $table) {
            $this->assertTrue($schema->hasTable($table), "Central schema is missing {$table}.");
        }

        $shop = Shop::query()->find(app(TenantContext::class)->id());

        $this->assertNotNull($shop);
    }

    public function test_tenant_migrations_create_only_operational_tables_and_marker_contract(): void
    {
        $shop = app(TenantContext::class)->shop();

        app(TenantConnectionManager::class)->within($shop, function () use ($shop): void {
            $schema = Schema::connection('tenant');

            $this->assertTrue($schema->hasTable('tenant_installations'));
            $this->assertTrue($schema->hasTable('users'));
            $this->assertTrue($schema->hasTable('items'));
            $this->assertTrue($schema->hasTable('sales'));
            $this->assertFalse($schema->hasTable('platform_users'));
            $this->assertFalse($schema->hasTable('shops'));

            foreach ($this->sharedInfrastructureTables() as $table) {
                $this->assertFalse($schema->hasTable($table), "Tenant schema contains shared {$table}.");
            }

            $this->assertTrue($schema->hasColumns('tenant_installations', [
                'shop_id',
                'target_fingerprint',
                'attestation_hmac',
                'connection_nonce',
            ]));

            $marker = DB::connection('tenant')
                ->table('tenant_installations')
                ->where('id', 1)
                ->first();

            $this->assertNotNull($marker);
            $this->assertSame($shop->getKey(), $marker->shop_id);
            $this->assertSame($shop->database_target_fingerprint, $marker->target_fingerprint);
            $this->assertSame($shop->databaseAttestationHmac(), $marker->attestation_hmac);
            $this->assertNull($marker->connection_nonce);
        });
    }

    public function test_database_backed_infrastructure_uses_explicit_central_connections(): void
    {
        $this->assertSame('central', config('database.default'));
        $this->assertSame('central', config('session.connection'));
        $this->assertSame('central', config('cache.stores.database.connection'));
        $this->assertSame('central', config('cache.stores.database.lock_connection'));
        $this->assertSame('central', config('queue.connections.database.connection'));
        $this->assertSame('central', config('queue.batching.database'));
        $this->assertSame('central', config('queue.failed.database'));
    }

    public function test_base_harness_never_rebinds_the_default_pdo_to_tenant(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'TestCase.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('aliasDefaultConnectionToTenant', $source);
        $this->assertStringNotContainsString('->setPdo(', $source);
        $this->assertStringNotContainsString('->setReadPdo(', $source);
    }

    public function test_default_harness_uses_manager_owned_isolated_database_files(): void
    {
        $manager = app(TenantConnectionManager::class);
        $shop = app(TenantContext::class)->shop();
        $centralDatabase = DB::connection('central')->getDatabaseName();
        $tenantDatabase = DB::connection('tenant')->getDatabaseName();
        $defaultConnection = DB::connection((string) config('database.default'));

        $this->assertFalse(method_exists($manager, 'bootstrapForTesting'));
        $this->assertTrue($shop->exists);
        $this->assertSame($shop->getKey(), Shop::query()->findOrFail($shop->getKey())->getKey());
        $this->assertNotSame($centralDatabase, $tenantDatabase);
        $this->assertFileExists($centralDatabase);
        $this->assertFileExists($tenantDatabase);
        $this->assertSame('central', config('database.default'));
        $this->assertSame(DB::connection('central')->getPdo(), $defaultConnection->getPdo());
        $this->assertNotSame($defaultConnection->getPdo(), DB::connection('tenant')->getPdo());
    }

    /** @return list<string> */
    private function sharedInfrastructureTables(): array
    {
        return [
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
        ];
    }
}
