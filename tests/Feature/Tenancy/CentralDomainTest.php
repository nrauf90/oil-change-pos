<?php

namespace Tests\Feature\Tenancy;

use App\Enums\ShopStatus;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopAccessSession;
use App\Models\Central\ShopFeature;
use App\Models\Central\ShopHealthSnapshot;
use App\Models\Central\ShopOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--realpath' => false,
            '--no-interaction' => true,
        ]);
    }

    public function test_central_models_never_use_the_tenant_connection(): void
    {
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);

        $this->assertSame('central', $shop->getConnectionName());
        $this->assertSame('central', (new PlatformUser)->getConnectionName());
        $this->assertSame('central', (new ShopOwner)->getConnectionName());
        $this->assertSame('central', (new ShopFeature)->getConnectionName());
        $this->assertSame('central', (new ShopAccessSession)->getConnectionName());
        $this->assertSame('central', (new ShopHealthSnapshot)->getConnectionName());
        $this->assertSame('active', $shop->status->value);
        $this->assertTrue(Str::isUuid($shop->getKey()));
    }

    public function test_central_relationships_persist_on_the_central_connection(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();
        $owner = $shop->owner()->create([
            'name' => 'Workshop Owner',
            'username' => 'workshop-owner',
            'email' => 'owner@example.com',
            'is_active' => true,
        ]);
        $feature = $shop->features()->create([
            'module_key' => 'inventory',
            'enabled' => true,
        ]);
        $accessSession = $shop->accessSessions()->create([
            'platform_user_id' => $platformUser->getKey(),
            'started_at' => now(),
            'reason' => 'Investigate inventory totals',
        ]);
        $healthSnapshot = $shop->healthSnapshot()->create([
            'migration_status' => 'current',
            'summary' => ['inventory_count' => 12],
        ]);

        $this->assertTrue($shop->owner()->firstOrFail()->is($owner));
        $this->assertTrue($shop->features()->firstOrFail()->is($feature));
        $this->assertTrue($shop->accessSessions()->firstOrFail()->is($accessSession));
        $this->assertTrue($shop->healthSnapshot()->firstOrFail()->is($healthSnapshot));
        $this->assertTrue($owner->shop()->firstOrFail()->is($shop));
        $this->assertTrue($feature->shop()->firstOrFail()->is($shop));
        $this->assertTrue($accessSession->shop()->firstOrFail()->is($shop));
        $this->assertTrue($accessSession->platformUser()->firstOrFail()->is($platformUser));
        $this->assertTrue($healthSnapshot->shop()->firstOrFail()->is($shop));
        $this->assertTrue($platformUser->shopAccessSessions()->firstOrFail()->is($accessSession));
    }

    public function test_sqlite_connection_templates_enforce_foreign_keys(): void
    {
        $centralForeignKeys = DB::connection('central')->selectOne('PRAGMA foreign_keys')->foreign_keys;
        $tenantForeignKeys = DB::connection('tenant')->selectOne('PRAGMA foreign_keys')->foreign_keys;

        $this->assertSame(1, $centralForeignKeys);
        $this->assertSame(1, $tenantForeignKeys);
    }

    public function test_shop_connection_overrides_are_not_serialized(): void
    {
        $shop = Shop::factory()->create([
            'database_host' => 'db.internal',
            'database_port' => 3307,
            'database_username' => 'shop-user',
            'database_password' => 'secret-password',
        ]);

        $arrayPayload = $shop->toArray();
        $jsonPayload = json_decode($shop->toJson(), true, flags: JSON_THROW_ON_ERROR);

        foreach (['database_host', 'database_port', 'database_username', 'database_password'] as $field) {
            $this->assertArrayNotHasKey($field, $arrayPayload);
            $this->assertArrayNotHasKey($field, $jsonPayload);
        }
    }

    public function test_shop_database_config_omits_missing_connection_overrides(): void
    {
        $shop = Shop::factory()->create();

        $this->assertSame([
            'driver' => 'sqlite',
            'database' => $shop->database_name,
        ], $shop->databaseConfig());
    }

    public function test_shop_database_config_returns_decrypted_connection_overrides(): void
    {
        $shop = Shop::factory()->create([
            'database_driver' => 'mysql',
            'database_name' => 'shop_alpha',
            'database_host' => 'db.internal',
            'database_port' => 3307,
            'database_username' => 'shop-user',
            'database_password' => 'secret-password',
        ]);

        $storedShop = DB::connection('central')->table('shops')->where('id', $shop->getKey())->first();

        $this->assertNotNull($storedShop);
        $this->assertNotSame('db.internal', $storedShop->database_host);
        $this->assertNotSame('3307', $storedShop->database_port);
        $this->assertNotSame('shop-user', $storedShop->database_username);
        $this->assertNotSame('secret-password', $storedShop->database_password);
        $this->assertSame([
            'driver' => 'mysql',
            'database' => 'shop_alpha',
            'host' => 'db.internal',
            'port' => 3307,
            'username' => 'shop-user',
            'password' => 'secret-password',
        ], $shop->databaseConfig());
    }

    public function test_platform_guard_uses_the_platform_user_provider(): void
    {
        $provider = Auth::guard('platform')->getProvider();

        $this->assertSame(PlatformUser::class, $provider->getModel());
    }
}
