<?php

namespace Tests\Feature\Console;

use App\Actions\Tenancy\CollectShopHealth;
use App\Actions\Tenancy\ProvisionShop;
use App\Data\ProvisionShopData;
use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Models\Central\Shop;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TenantOperationsCommandTest extends TestCase
{
    private string $databaseRoot;

    private string $tenantRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));

        $this->databaseRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tenant-operations-'.Str::uuid();
        $this->tenantRoot = $this->databaseRoot.DIRECTORY_SEPARATOR.'tenants';
        (new Filesystem)->ensureDirectoryExists($this->tenantRoot, 0700);

        $centralDatabase = $this->databaseRoot.DIRECTORY_SEPARATOR.'central.sqlite';
        $this->createEmptyDatabase($centralDatabase);

        config()->set('database.default', 'central');
        config()->set('database.tenant_sqlite_root', $this->tenantRoot);
        config()->set(
            'database.tenant_attestation_lock_path',
            $this->databaseRoot.DIRECTORY_SEPARATOR.'attestation-locks',
        );
        config()->set('cache.default', 'database');
        config()->set('cache.stores.database.connection', 'central');
        config()->set('cache.stores.database.lock_connection', 'central');
        $this->configureCentralDatabase($centralDatabase);
        DB::purge('tenant');
        $this->migrateCentralDatabase();
    }

    protected function tearDown(): void
    {
        try {
            app(TenantConnectionManager::class)->disconnect();
            DB::purge('central');
        } finally {
            (new Filesystem)->deleteDirectory($this->databaseRoot);
            parent::tearDown();
        }
    }

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    public function test_migrate_targets_only_the_requested_eligible_shop(): void
    {
        $selected = $this->createOperationalShop('bravo-shop', ShopStatus::Active);
        $unselected = $this->createOperationalShop('alpha-shop', ShopStatus::Active);

        $this->artisan('tenants:migrate', [
            '--shop' => $selected->slug,
            '--force' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('bravo-shop: migrated')
            ->expectsOutputToContain('succeeded=1 no-op=0 failed=0')
            ->assertSuccessful();

        $this->assertTrue($this->tenantHasTable($selected, 'users'));
        $this->assertFalse($this->tenantHasTable($unselected, 'users'));
        $this->assertDatabaseHas('shop_lifecycle_activities', [
            'shop_id' => $selected->getKey(),
            'event' => ShopLifecycleEvent::MigrationSucceeded->value,
        ], 'central');
        $this->assertDatabaseMissing('shop_lifecycle_activities', [
            'shop_id' => $unselected->getKey(),
            'event' => ShopLifecycleEvent::MigrationSucceeded->value,
        ], 'central');
        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    public function test_migrate_all_includes_active_and_suspended_shops_and_continues_after_failure(): void
    {
        $active = $this->createOperationalShop('alpha-shop', ShopStatus::Active);
        $missingDatabase = $this->createShopWithMissingDatabase('bravo-shop');
        $suspended = $this->createOperationalShop('charlie-shop', ShopStatus::Suspended);
        $ineligible = $this->createOperationalShop('delta-shop', ShopStatus::Provisioning);

        $this->artisan('tenants:migrate', [
            '--force' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('alpha-shop: migrated')
            ->expectsOutputToContain('bravo-shop: failed')
            ->expectsOutputToContain('charlie-shop: migrated')
            ->expectsOutputToContain('succeeded=2 no-op=0 failed=1')
            ->assertExitCode(1);

        $this->assertTrue($this->tenantHasTable($active, 'users'));
        $this->assertTrue($this->tenantHasTable($suspended, 'users'));
        $this->assertFalse($this->tenantHasTable($ineligible, 'users'));
        $this->assertFileDoesNotExist((string) $missingDatabase->database_name);
        $this->assertDatabaseHas('shop_lifecycle_activities', [
            'shop_id' => $missingDatabase->getKey(),
            'event' => ShopLifecycleEvent::MigrationFailed->value,
        ], 'central');
        $this->assertSame(ShopStatus::Active, $missingDatabase->fresh()->status);
        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    public function test_migrate_rejects_an_unknown_or_ineligible_selector_without_connecting(): void
    {
        $ineligible = $this->createOperationalShop('provisioning-shop', ShopStatus::Provisioning);

        $this->artisan('tenants:migrate', [
            '--shop' => $ineligible->slug,
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(2);

        $this->artisan('tenants:migrate', [
            '--shop' => 'unknown-shop',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(2);

        $this->assertFalse($this->tenantHasTable($ineligible, 'users'));
        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    public function test_health_returns_nonzero_when_any_requested_shop_is_unhealthy(): void
    {
        if (! class_exists(CollectShopHealth::class)) {
            $this->markTestSkipped('Task 7 owns CollectShopHealth and has not been integrated yet.');
        }

        app(ProvisionShop::class)->handle(new ProvisionShopData(
            name: 'Healthy Shop',
            slug: 'healthy-shop',
            databaseDriver: 'sqlite',
            databaseName: $this->tenantRoot.DIRECTORY_SEPARATOR.'healthy-shop.sqlite',
            ownerName: 'Healthy Owner',
            ownerUsername: 'healthy-owner',
            ownerEmail: 'healthy@example.test',
            temporaryOwnerPassword: 'temporary-owner-password',
        ));
        $this->createShopWithMissingDatabase('unhealthy-shop');

        $this->artisan('tenants:health', [
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('healthy-shop: healthy')
            ->expectsOutputToContain('unhealthy-shop: unhealthy')
            ->assertExitCode(1);

        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    private function createOperationalShop(
        string $slug,
        ShopStatus $status,
    ): Shop {
        $shop = Shop::registerForProvisioning(
            name: Str::headline($slug),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $this->tenantRoot.DIRECTORY_SEPARATOR.$slug.'.sqlite',
        );

        $this->createTenantDatabase($shop);

        if ($status !== ShopStatus::Provisioning) {
            $shop->markActive();
        }

        if ($status === ShopStatus::Suspended) {
            $shop->suspend();
        }

        return $shop->fresh();
    }

    private function createShopWithMissingDatabase(string $slug): Shop
    {
        $shop = Shop::registerForProvisioning(
            name: Str::headline($slug),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $this->tenantRoot.DIRECTORY_SEPARATOR.$slug.'.sqlite',
        );
        $shop->markActive();

        return $shop->fresh();
    }

    private function tenantHasTable(Shop $shop, string $table): bool
    {
        try {
            return app(TenantConnectionManager::class)->within(
                $shop,
                static fn (): bool => Schema::connection('tenant')->hasTable($table),
            );
        } finally {
            app(TenantConnectionManager::class)->disconnect();
        }
    }

    private function createEmptyDatabase(string $database): void
    {
        $handle = @fopen($database, 'x+b');

        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to create the test database.');
        }

        fclose($handle);
    }
}
