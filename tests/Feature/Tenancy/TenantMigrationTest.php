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
        $this->assertSame($defaultConnection->getPdo(), DB::connection('tenant')->getPdo());
    }
}
