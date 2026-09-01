<?php

namespace Tests\Feature\Tenancy;

use App\Actions\RecordSale;
use App\Enums\ItemType;
use App\Enums\SaleLineType;
use App\Http\Requests\QuickItemRequest;
use App\Models\ActivityLog;
use App\Models\Central\Shop;
use App\Models\CustomerVehicle;
use App\Models\Expense;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\Item;
use App\Models\ItemVehicleCompatibility;
use App\Models\ModuleSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Supply;
use App\Models\User;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\DatabaseTargetConfiguration;
use App\Tenancy\Exceptions\TenantDatabaseAttestationFailed;
use App\Tenancy\Exceptions\TenantNotInitialized;
use App\Tenancy\NormalizedDatabaseTarget;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\ValidatedTenantConnection;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

class TenantConnectionIsolationTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    private TenantConnectionManager $manager;

    private Shop $shopA;

    private Shop $shopB;

    private string $tenantRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantRoot = $this->newTemporaryDirectory('tenant-isolation-');
        $centralDatabase = $this->tenantRoot.DIRECTORY_SEPARATOR.'central.sqlite';

        if (File::put($centralDatabase, '') === false) {
            throw new RuntimeException('Unable to create the central test database.');
        }

        config()->set('database.default', 'sqlite');
        config()->set('database.tenant_sqlite_root', $this->tenantRoot);
        config()->set('database.connections.central', $this->sqliteConfiguration($centralDatabase));
        config()->set('database.connections.tenant', $this->sqliteConfiguration(':memory:'));
        DB::purge('central');
        DB::purge('tenant');

        Artisan::call('migrate', [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--realpath' => false,
            '--no-interaction' => true,
        ]);

        $this->manager = app(TenantConnectionManager::class);
        $this->shopA = $this->createMigratedTenant('shop-a');
        $this->shopB = $this->createMigratedTenant('shop-b');
    }

    protected function tearDown(): void
    {
        try {
            $this->manager->disconnect();
            DB::purge('central');

            foreach ($this->temporaryDirectories as $temporaryDirectory) {
                File::deleteDirectory($temporaryDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    public function test_operational_models_fail_closed_without_tenant_context(): void
    {
        $this->manager->disconnect();

        $this->expectException(TenantNotInitialized::class);

        Item::query()->count();
    }

    public function test_every_operational_model_uses_the_fail_closed_tenant_contract(): void
    {
        $operationalModels = [
            ActivityLog::class,
            CustomerVehicle::class,
            Expense::class,
            Inspection::class,
            InspectionItem::class,
            Item::class,
            ItemVehicleCompatibility::class,
            ModuleSetting::class,
            Sale::class,
            SaleItem::class,
            Supplier::class,
            SupplierPayment::class,
            Supply::class,
            VehicleMake::class,
            VehicleModel::class,
            User::class,
            Role::class,
            Permission::class,
        ];
        $this->manager->disconnect();

        foreach ($operationalModels as $operationalModel) {
            try {
                (new $operationalModel)->getConnectionName();
                $this->fail("{$operationalModel} resolved without tenant context.");
            } catch (TenantNotInitialized) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('central', (new Shop)->getConnectionName());
        $this->manager->connect($this->shopA);

        foreach ($operationalModels as $operationalModel) {
            $model = new $operationalModel;
            $this->assertSame('tenant', $model->getConnectionName());

            try {
                $model->setConnection('sqlite');
                $this->fail("{$operationalModel} bypassed the tenant connection.");
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Operational models cannot override the tenant connection.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_items_and_observer_activity_are_isolated_across_a_b_a_switches(): void
    {
        $this->manager->connect($this->shopA);
        $item = Item::factory()->create(['name' => 'Only in shop A']);

        $this->assertSame('tenant', $item->getConnectionName());
        $this->assertTrue(ActivityLog::query()->where('description', 'like', '%Only in shop A%')->exists());
        $this->manager->disconnect();

        $this->manager->connect($this->shopB);
        $this->assertFalse(Item::query()->where('name', 'Only in shop A')->exists());
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%Only in shop A%')->exists());
        $this->manager->disconnect();

        $this->manager->connect($this->shopA);
        $this->assertTrue(Item::query()->where('name', 'Only in shop A')->exists());
        $this->assertSame('sqlite', config('database.default'));
    }

    public function test_wrong_tenant_installation_marker_is_rejected_before_context_initializes(): void
    {
        $this->replaceMarkerShopId($this->shopA, (string) Str::uuid());

        try {
            $this->manager->connect($this->shopA);
            $this->fail('A database with the wrong tenant marker was accepted.');
        } catch (TenantDatabaseAttestationFailed $exception) {
            $this->assertSame('Tenant database identity attestation failed.', $exception->getMessage());
        }

        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_mysql_dns_drift_is_rejected_before_pdo_open_while_the_tls_hostname_is_preserved(): void
    {
        $initialResolver = new class implements DatabaseHostResolver
        {
            public function resolve(string $host): array
            {
                return ['192.0.2.10'];
            }
        };
        $target = NormalizedDatabaseTarget::forTenant(
            target: new DatabaseTargetConfiguration(
                driver: 'mysql',
                database: 'dns_re_attestation',
                host: 'tenant-db.example.test',
            ),
            defaults: new DatabaseTargetConfiguration('mysql', 'ignored'),
            central: null,
            sqliteRoot: $this->tenantRoot,
            hostResolver: $initialResolver,
        );
        $snapshot = new ValidatedTenantConnection([
            'driver' => 'mysql',
            'database' => 'dns_re_attestation',
            'host' => 'tenant-db.example.test',
            'port' => 3306,
        ], $target);
        $descriptor = new class extends Shop
        {
            public ValidatedTenantConnection $snapshot;

            public function validatedDatabaseConnection(): ValidatedTenantConnection
            {
                return $this->snapshot;
            }
        };
        $descriptor->snapshot = $snapshot;
        $descriptor->setRawAttributes(['id' => (string) Str::uuid()], true);
        $descriptor->exists = true;
        app()->instance(DatabaseHostResolver::class, new class implements DatabaseHostResolver
        {
            public function resolve(string $host): array
            {
                return ['192.0.2.11'];
            }
        });

        $this->assertSame('tenant-db.example.test', $snapshot->connectionOverrides()['host']);

        try {
            $this->manager->connect($descriptor);
            $this->fail('A tenant database endpoint that drifted after validation was accepted.');
        } catch (TenantDatabaseAttestationFailed) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_mysql_endpoint_reattestation_canonicalizes_addresses_and_skips_unix_sockets(): void
    {
        $initialResolver = new class implements DatabaseHostResolver
        {
            public function resolve(string $host): array
            {
                return ['192.0.2.12'];
            }
        };
        $target = NormalizedDatabaseTarget::forTenant(
            target: new DatabaseTargetConfiguration(
                driver: 'mysql',
                database: 'endpoint_re_attestation',
                host: 'tenant-db.example.test',
            ),
            defaults: new DatabaseTargetConfiguration('mysql', 'ignored'),
            central: null,
            sqliteRoot: $this->tenantRoot,
            hostResolver: $initialResolver,
        );
        $sameEndpointResolver = new class implements DatabaseHostResolver
        {
            public function resolve(string $host): array
            {
                return ['::ffff:192.0.2.12'];
            }
        };
        $driftedEndpointResolver = new class implements DatabaseHostResolver
        {
            public function resolve(string $host): array
            {
                return ['192.0.2.13'];
            }
        };

        $this->assertTrue($target->hasCurrentMySqlEndpoints($sameEndpointResolver));
        $this->assertFalse($target->hasCurrentMySqlEndpoints($driftedEndpointResolver));
        $this->assertSame('tenant-db.example.test', $target->host);

        $socketResolver = new class implements DatabaseHostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                $this->calls++;

                throw new RuntimeException('Socket targets must not resolve a host.');
            }
        };
        $socketTarget = NormalizedDatabaseTarget::forTenant(
            target: new DatabaseTargetConfiguration(
                driver: 'mysql',
                database: 'socket_re_attestation',
                host: 'ignored.example.test',
                socket: $this->tenantRoot.DIRECTORY_SEPARATOR.'mysql.sock',
            ),
            defaults: new DatabaseTargetConfiguration('mysql', 'ignored'),
            central: null,
            sqliteRoot: $this->tenantRoot,
            hostResolver: $socketResolver,
        );

        $this->assertTrue($socketTarget->hasCurrentMySqlEndpoints($socketResolver));
        $this->assertSame(0, $socketResolver->calls);
    }

    public function test_sqlite_file_swap_after_validation_is_rejected_even_with_a_matching_marker(): void
    {
        $replacement = $this->tenantRoot.DIRECTORY_SEPARATOR.'replacement.sqlite';

        if (File::put($replacement, '') === false) {
            throw new RuntimeException('Unable to create replacement tenant database.');
        }

        $this->writeTenantMarker($replacement, $this->shopA);
        $original = (string) $this->shopA->database_name;
        $backup = $original.'.validated';
        $snapshot = $this->shopA->validatedDatabaseConnection();
        $swap = static function () use ($original, $backup, $replacement): void {
            if (! rename($original, $backup) || ! rename($replacement, $original)) {
                throw new RuntimeException('Unable to swap the SQLite test database.');
            }
        };
        $descriptor = new class extends Shop
        {
            public ValidatedTenantConnection $snapshot;

            public Closure $swap;

            public function validatedDatabaseConnection(): ValidatedTenantConnection
            {
                ($this->swap)();

                return $this->snapshot;
            }
        };
        $descriptor->snapshot = $snapshot;
        $descriptor->swap = $swap;
        $descriptor->setRawAttributes($this->shopA->getAttributes(), true);
        $descriptor->exists = true;

        $this->expectException(TenantDatabaseAttestationFailed::class);

        $this->manager->connect($descriptor);
    }

    public function test_disconnect_purges_dynamic_configuration_and_cannot_reconnect_the_last_shop(): void
    {
        $this->manager->connect($this->shopA);
        $this->assertSame((string) $this->shopA->database_name, DB::connection('tenant')->getDatabaseName());

        $this->manager->disconnect();

        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
        $this->assertSame(
            'spatie.permission.cache.no-tenant',
            app(PermissionRegistrar::class)->cacheKey,
        );

        try {
            DB::connection('tenant')->getPdo();
            $this->fail('The purged tenant connection reached the previous shop.');
        } catch (Throwable $exception) {
            $this->assertStringNotContainsString((string) $this->shopA->database_name, $exception->getMessage());
        }
    }

    public function test_within_owns_cleanup_and_rejects_a_nested_different_shop(): void
    {
        $result = $this->manager->within($this->shopA, function (): string {
            $this->assertSame($this->shopA->getKey(), app(TenantContext::class)->id());

            try {
                $this->manager->within($this->shopB, static fn (): null => null);
                $this->fail('A nested different-tenant scope was accepted.');
            } catch (LogicException $exception) {
                $this->assertSame('A different tenant is already initialized.', $exception->getMessage());
            }

            return 'completed';
        });

        $this->assertSame('completed', $result);
        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_spatie_models_pivots_and_cache_are_isolated_across_a_b_a_switches(): void
    {
        $this->manager->connect($this->shopA);
        $permission = Permission::findOrCreate('tenant-a-only', 'web');
        $role = Role::findOrCreate('shared-role-name', 'web');
        $role->givePermissionTo($permission);
        $user = User::factory()->create();
        $user->assignRole($role);
        $shopAUserId = $user->getKey();
        $shopACacheKey = (string) config('permission.cache.key');

        $this->assertTrue($user->fresh()->hasPermissionTo('tenant-a-only'));
        $this->assertSame('tenant', $user->roles()->firstOrFail()->getConnectionName());
        $this->manager->disconnect();

        $this->manager->connect($this->shopB);
        $this->assertNotSame($shopACacheKey, config('permission.cache.key'));
        $this->assertFalse(Permission::query()->where('name', 'tenant-a-only')->exists());
        $this->assertFalse(Role::query()->where('name', 'shared-role-name')->exists());
        $this->manager->disconnect();

        $this->manager->connect($this->shopA);
        $this->assertTrue(User::query()->findOrFail($shopAUserId)->hasPermissionTo('tenant-a-only'));
        $this->assertSame($shopACacheKey, config('permission.cache.key'));
    }

    public function test_model_backed_validation_reads_only_the_active_tenant(): void
    {
        $this->manager->connect($this->shopA);
        Item::factory()->create(['name' => 'Tenant-scoped unique name']);
        $this->manager->disconnect();

        $this->manager->connect($this->shopB);
        $rules = (new QuickItemRequest)->rules();
        $attributes = ['name' => 'Tenant-scoped unique name', 'type' => ItemType::Repair->value];

        $this->assertTrue(Validator::make($attributes, $rules)->passes());

        Item::factory()->repair()->create(['name' => 'Tenant-scoped unique name']);

        $this->assertTrue(Validator::make($attributes, $rules)->fails());
    }

    public function test_model_owned_transaction_writes_only_to_the_active_tenant(): void
    {
        $this->manager->connect($this->shopA);
        $this->actingAs(User::factory()->create());

        $sale = (new RecordSale)([
            'lines' => [[
                'type' => SaleLineType::Custom->value,
                'item_id' => null,
                'item_name' => 'Tenant transaction line',
                'quantity' => 1,
                'manually_charged_price' => '125.00',
            ]],
        ]);

        $this->assertSame('tenant', $sale->getConnectionName());
        $this->assertSame('Tenant transaction line', $sale->lines()->firstOrFail()->item_name);
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('sales'));
    }

    public function test_model_owned_transaction_rolls_back_tenant_writes(): void
    {
        $this->manager->connect($this->shopA);
        $this->actingAs(User::factory()->create());
        $eventName = 'eloquent.created: '.SaleItem::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Forced tenant transaction failure.');
        });

        try {
            (new RecordSale)([
                'lines' => [[
                    'type' => SaleLineType::Custom->value,
                    'item_id' => null,
                    'item_name' => 'Rolled back tenant line',
                    'quantity' => 1,
                    'manually_charged_price' => '125.00',
                ]],
            ]);
            $this->fail('The forced tenant transaction failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced tenant transaction failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, SaleItem::query()->count());
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('sales'));
    }

    private function createMigratedTenant(string $slug): Shop
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.$slug.'.sqlite';

        if (File::put($database, '') === false) {
            throw new RuntimeException('Unable to create a tenant test database.');
        }

        $shop = Shop::registerForProvisioning(
            name: str($slug)->headline()->toString(),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $database,
        );
        $this->writeTenantMarker($database, $shop);
        $this->manager->connect($shop);

        try {
            Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations',
                '--realpath' => false,
                '--no-interaction' => true,
            ]);
        } finally {
            $this->manager->disconnect();
        }

        return $shop;
    }

    private function writeTenantMarker(string $database, Shop $shop): void
    {
        $connection = DB::build($this->sqliteConfiguration($database));

        try {
            $connection->statement(<<<'SQL'
                CREATE TABLE tenant_installations (
                    id INTEGER PRIMARY KEY,
                    shop_id VARCHAR(36) NOT NULL UNIQUE,
                    target_fingerprint VARCHAR(64) NOT NULL,
                    created_at DATETIME NOT NULL
                )
                SQL);
            $connection->table('tenant_installations')->insert([
                'id' => 1,
                'shop_id' => $shop->getKey(),
                'target_fingerprint' => $shop->database_target_fingerprint,
                'created_at' => now(),
            ]);
        } finally {
            DB::purge($connection->getName());
        }
    }

    private function replaceMarkerShopId(Shop $shop, string $shopId): void
    {
        $connection = DB::build($this->sqliteConfiguration((string) $shop->database_name));

        try {
            $connection->table('tenant_installations')->where('id', 1)->update(['shop_id' => $shopId]);
        } finally {
            DB::purge($connection->getName());
        }
    }

    /** @return array<string, mixed> */
    private function sqliteConfiguration(string $database): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'transaction_mode' => 'DEFERRED',
        ];
    }

    private function newTemporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.Str::uuid();

        if (! File::makeDirectory($directory, 0700, true)) {
            throw new RuntimeException('Unable to create tenant test directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
