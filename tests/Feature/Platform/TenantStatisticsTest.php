<?php

namespace Tests\Feature\Platform;

use App\Actions\Tenancy\CollectShopHealth;
use App\Actions\Tenancy\CollectTenantStatistics;
use App\Actions\Tenancy\ProvisionShop;
use App\Data\ProvisionShopData;
use App\Enums\DashboardPeriod;
use App\Models\ActivityLog;
use App\Models\Central\Shop;
use App\Models\Expense;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\AdminDashboardMetrics;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Mockery;
use RuntimeException;

class TenantStatisticsTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
    }

    public function test_statistics_report_only_the_selected_shops_totals(): void
    {
        $this->travelTo('2026-09-02 12:00:00');
        $selectedShop = $this->provisionShop('selected-shop');
        $otherShop = $this->provisionShop('other-shop');
        $selectedInventoryCount = 0;

        resolve(TenantConnectionManager::class)->within($selectedShop, function () use (&$selectedInventoryCount): void {
            $owner = User::query()->where('username', 'selected-shop-owner')->firstOrFail();
            User::factory()->create();
            User::factory()->inactive()->create();
            $item = Item::factory()->create(['unit_cost' => '40.00']);
            Item::factory()->inactive()->create();
            $selectedInventoryCount = Item::query()->active()->count();
            $sale = Sale::factory()->create([
                'total_amount' => '150.00',
                'created_at' => '2026-09-02 10:00:00',
            ]);
            SaleItem::factory()->create([
                'sale_id' => $sale->getKey(),
                'item_id' => $item->getKey(),
                'quantity' => 2,
                'manually_charged_price' => '150.00',
            ]);
            Expense::factory()->create([
                'user_id' => $owner->getKey(),
                'amount' => '20.00',
                'spent_at' => '2026-09-02 11:00:00',
            ]);
            ActivityLog::factory()->bySystem()->create([
                'description' => 'Selected shop latest activity.',
                'created_at' => '2026-09-02 12:30:00',
            ]);
        });

        resolve(TenantConnectionManager::class)->within($otherShop, function (): void {
            $item = Item::factory()->create(['unit_cost' => '1.00']);
            $sale = Sale::factory()->create([
                'total_amount' => '9999.00',
                'created_at' => '2026-09-02 10:00:00',
            ]);
            SaleItem::factory()->create([
                'sale_id' => $sale->getKey(),
                'item_id' => $item->getKey(),
                'quantity' => 1,
                'manually_charged_price' => '9999.00',
            ]);
        });

        $statistics = resolve(CollectTenantStatistics::class)->handle(
            $selectedShop,
            DashboardPeriod::Today,
        );

        $this->assertSame('150.00', $statistics['sales']);
        $this->assertSame('20.00', $statistics['expenses']);
        $this->assertSame('70.00', $statistics['gross_margin']);
        $this->assertSame(1, $statistics['transaction_count']);
        $this->assertSame(2, $statistics['active_users']);
        $this->assertSame($selectedInventoryCount, $statistics['inventory_count']);
        $this->assertSame('2026-09-02T12:30:00+00:00', $statistics['last_activity_at']);
        $this->assertFalse(resolve(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_statistics_clear_tenant_context_when_metric_collection_fails(): void
    {
        $shop = $this->provisionShop('failing-statistics');
        $metrics = Mockery::mock(AdminDashboardMetrics::class);
        $metrics->shouldReceive('comparison')
            ->once()
            ->with(DashboardPeriod::Today)
            ->andReturnUsing(function () use ($shop): never {
                $this->assertTrue(resolve(TenantContext::class)->initialized());
                $this->assertSame((string) $shop->getKey(), resolve(TenantContext::class)->id());

                throw new RuntimeException('Simulated metric failure.');
            });
        $this->app->instance(AdminDashboardMetrics::class, $metrics);

        $this->assertThrows(
            fn () => resolve(CollectTenantStatistics::class)->handle($shop, DashboardPeriod::Today),
            RuntimeException::class,
            'Simulated metric failure.',
        );
        $this->assertFalse(resolve(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_health_collection_records_safe_current_state_and_clears_tenant_context(): void
    {
        $shop = $this->provisionShop('healthy-shop');

        $snapshot = resolve(CollectShopHealth::class)->handle($shop);

        $this->assertSame((string) $shop->getKey(), (string) $snapshot->shop_id);
        $this->assertSame('current', $snapshot->migration_status);
        $this->assertSame('healthy', $snapshot->summary['connection_status']);
        $this->assertSame('current', $snapshot->summary['seed_status']);
        $this->assertSame(0, $snapshot->summary['pending_migrations']);
        $this->assertNotNull($snapshot->last_successful_connection_at);
        $this->assertStringNotContainsString((string) $shop->database_name, $snapshot->toJson());
        $this->assertFalse(resolve(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_health_collection_returns_an_unavailable_snapshot_without_exposing_target_details(): void
    {
        $database = rtrim((string) config('database.tenant_sqlite_root'), '/\\')
            .DIRECTORY_SEPARATOR.'missing-private-target.sqlite';
        $shop = Shop::registerForProvisioning(
            name: 'Unavailable Shop',
            slug: 'unavailable-shop',
            databaseDriver: 'sqlite',
            databaseName: $database,
            databaseUsername: 'private-user',
            databasePassword: 'private-password',
        );
        $shop->markProvisioningFailed('Connection unavailable.');

        $snapshot = resolve(CollectShopHealth::class)->handle($shop->fresh());

        $this->assertSame('unavailable', $snapshot->migration_status);
        $this->assertSame('unavailable', $snapshot->summary['connection_status']);
        $this->assertSame('unknown', $snapshot->summary['seed_status']);
        $this->assertStringNotContainsString($database, $snapshot->toJson());
        $this->assertStringNotContainsString('private-user', $snapshot->toJson());
        $this->assertStringNotContainsString('private-password', $snapshot->toJson());
        $this->assertFalse(resolve(TenantContext::class)->initialized());
    }

    private function provisionShop(string $slug): Shop
    {
        $database = rtrim((string) config('database.tenant_sqlite_root'), '/\\')
            .DIRECTORY_SEPARATOR."{$slug}.sqlite";

        return resolve(ProvisionShop::class)->handle(new ProvisionShopData(
            name: str($slug)->replace('-', ' ')->title()->toString(),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $database,
            ownerName: 'Test Owner',
            ownerUsername: "{$slug}-owner",
            ownerEmail: "{$slug}@example.test",
            temporaryOwnerPassword: 'temporary-owner-password',
        ));
    }
}
