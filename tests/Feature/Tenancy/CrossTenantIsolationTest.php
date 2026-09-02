<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\ExpenseCategory;
use App\Models\ActivityLog;
use App\Models\Central\Shop;
use App\Models\CustomerVehicle;
use App\Models\Expense;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use RuntimeException;
use Tests\TestCase;

class CrossTenantIsolationTest extends TestCase
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

        $this->tenantRoot = $this->newTemporaryDirectory('cross-tenant-isolation-');
        $centralDatabase = $this->tenantRoot.DIRECTORY_SEPARATOR.'central.sqlite';

        if (File::put($centralDatabase, '') === false) {
            throw new RuntimeException('Unable to create the central test database.');
        }

        config()->set('app.url', 'https://pos.example.test');
        config()->set('database.default', 'central');
        config()->set('database.tenant_sqlite_root', $this->tenantRoot);
        config()->set(
            'database.tenant_attestation_lock_path',
            $this->tenantRoot.DIRECTORY_SEPARATOR.'attestation-locks',
        );
        $this->configureCentralDatabase($centralDatabase);
        DB::purge('tenant');
        $this->migrateCentralDatabase();

        $this->manager = app(TenantConnectionManager::class);
        $this->shopA = $this->createActiveTenant('collision-shop-a');
        $this->shopB = $this->createActiveTenant('collision-shop-b');
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

    /**
     * Regression caught: resolving every tenant request to the first active shop
     * makes the B URLs below disclose, update, and delete A's colliding item.
     */
    public function test_colliding_item_routes_only_read_update_and_delete_the_active_tenant_record(): void
    {
        $itemAId = $this->seedTenant($this->shopA, function (): int {
            $this->createOwner('Tenant A owner');

            return Item::factory()->create([
                'name' => 'Tenant A collision filter',
                'unit_cost' => 110,
            ])->getKey();
        });
        $itemBId = $this->seedTenant($this->shopB, function (): int {
            $this->createOwner('Tenant B owner');

            return Item::factory()->create([
                'name' => 'Tenant B collision filter',
                'unit_cost' => 220,
            ])->getKey();
        });

        $this->assertSame($itemAId, $itemBId);
        $this->loginTo($this->shopB);

        $this->get($this->tenantUrl($this->shopB, "/items/{$itemBId}/edit"))
            ->assertOk()
            ->assertSee('Tenant B collision filter')
            ->assertDontSee('Tenant A collision filter');
        $this->assertTenantStateIsRevoked();

        $this->put($this->tenantUrl($this->shopB, "/items/{$itemBId}"), [
            'name' => 'Tenant B collision filter updated',
            'type' => 'product',
            'unit_of_measure' => 'piece',
            'unit_cost' => 330,
        ])->assertRedirect();
        $this->assertTenantStateIsRevoked();

        $this->assertSame(
            ['Tenant A collision filter', '110.00'],
            $this->tenantItemState($this->shopA, $itemAId),
        );
        $this->assertSame(
            ['Tenant B collision filter updated', '330.00'],
            $this->tenantItemState($this->shopB, $itemBId),
        );

        $this->delete($this->tenantUrl($this->shopB, "/items/{$itemBId}"))->assertRedirect();
        $this->assertTenantStateIsRevoked();
        $this->assertSame(
            ['Tenant A collision filter', '110.00'],
            $this->tenantItemState($this->shopA, $itemAId),
        );
        $this->assertNull($this->tenantItemState($this->shopB, $itemBId));

        $this->logoutFrom($this->shopB);
        $this->loginTo($this->shopA);
        $this->get($this->tenantUrl($this->shopA, "/items/{$itemAId}/edit"))
            ->assertOk()
            ->assertSee('Tenant A collision filter')
            ->assertDontSee('Tenant B collision filter updated');
        $this->assertTenantStateIsRevoked();
    }

    /**
     * Regression caught: selecting A for B's host exposes A's invoice and lets
     * an authorized B owner delete A's colliding sale and line items.
     */
    public function test_colliding_sale_routes_only_read_and_delete_the_active_tenant_invoice(): void
    {
        $saleA = $this->seedSale($this->shopA, 'Tenant A owner', 'TENANT-A-INVOICE', 'Tenant A customer', 'Tenant A line');
        $saleB = $this->seedSale($this->shopB, 'Tenant B owner', 'TENANT-B-INVOICE', 'Tenant B customer', 'Tenant B line');

        $this->assertSame($saleA['sale_id'], $saleB['sale_id']);
        $this->assertSame($saleA['line_id'], $saleB['line_id']);
        $this->loginTo($this->shopB);

        $this->get($this->tenantUrl($this->shopB, "/sales/{$saleB['sale_id']}"))
            ->assertOk()
            ->assertSee('TENANT-B-INVOICE')
            ->assertSee('Tenant B customer')
            ->assertSee('Tenant B line')
            ->assertDontSee('TENANT-A-INVOICE')
            ->assertDontSee('Tenant A customer')
            ->assertDontSee('Tenant A line');
        $this->assertTenantStateIsRevoked();

        $this->delete($this->tenantUrl($this->shopB, "/sales/{$saleB['sale_id']}"))->assertRedirect();
        $this->assertTenantStateIsRevoked();
        $this->assertSame(
            ['TENANT-A-INVOICE', 'Tenant A customer', 'Tenant A line'],
            $this->tenantSaleState($this->shopA, $saleA['sale_id']),
        );
        $this->assertNull($this->tenantSaleState($this->shopB, $saleB['sale_id']));
    }

    /**
     * Regression caught: selecting A for B's host makes the ordinary expense
     * binding disclose, update, and delete A's colliding cash record.
     */
    public function test_colliding_expense_routes_only_read_update_and_delete_the_active_tenant_record(): void
    {
        $expenseAId = $this->seedExpense($this->shopA, 'Tenant A owner', 'Tenant A collision rent', 410);
        $expenseBId = $this->seedExpense($this->shopB, 'Tenant B owner', 'Tenant B collision rent', 520);

        $this->assertSame($expenseAId, $expenseBId);
        $this->loginTo($this->shopB);

        $this->get($this->tenantUrl($this->shopB, "/expenses/{$expenseBId}/edit"))
            ->assertOk()
            ->assertSee('Tenant B collision rent')
            ->assertDontSee('Tenant A collision rent');
        $this->assertTenantStateIsRevoked();

        $this->put($this->tenantUrl($this->shopB, "/expenses/{$expenseBId}"), [
            'category' => ExpenseCategory::Utility->value,
            'amount' => 630,
            'description' => 'Tenant B collision utility updated',
        ])->assertRedirect();
        $this->assertTenantStateIsRevoked();

        $this->assertSame(
            ['Tenant A collision rent', '410.00', ExpenseCategory::Rent->value],
            $this->tenantExpenseState($this->shopA, $expenseAId),
        );
        $this->assertSame(
            ['Tenant B collision utility updated', '630.00', ExpenseCategory::Utility->value],
            $this->tenantExpenseState($this->shopB, $expenseBId),
        );

        $this->delete($this->tenantUrl($this->shopB, "/expenses/{$expenseBId}"))->assertRedirect();
        $this->assertTenantStateIsRevoked();
        $this->assertSame(
            ['Tenant A collision rent', '410.00', ExpenseCategory::Rent->value],
            $this->tenantExpenseState($this->shopA, $expenseAId),
        );
        $this->assertNull($this->tenantExpenseState($this->shopB, $expenseBId));
    }

    /**
     * Regression caught: resolving B's list requests to A returns A's customer
     * profile and append-only activity entry despite the colliding row IDs.
     */
    public function test_customer_vehicle_and_activity_queries_only_return_the_active_tenant_rows(): void
    {
        $tenantA = $this->seedCustomerVehicleAndActivity(
            $this->shopA,
            'Tenant A owner',
            'Tenant A collision customer',
            'Tenant A collision activity marker',
        );
        $tenantB = $this->seedCustomerVehicleAndActivity(
            $this->shopB,
            'Tenant B owner',
            'Tenant B collision customer',
            'Tenant B collision activity marker',
        );

        $this->assertSame($tenantA['vehicle_id'], $tenantB['vehicle_id']);
        $this->assertSame($tenantA['activity_id'], $tenantB['activity_id']);
        $this->loginTo($this->shopB);

        $this->getJson($this->tenantUrl($this->shopB, '/customer-vehicles?q=collision'))
            ->assertOk()
            ->assertJsonFragment(['customer_name' => 'Tenant B collision customer'])
            ->assertJsonMissing(['customer_name' => 'Tenant A collision customer']);
        $this->assertTenantStateIsRevoked();

        $this->get($this->tenantUrl($this->shopB, '/activity-log?q=collision+activity+marker'))
            ->assertOk()
            ->assertSee('Tenant B collision activity marker')
            ->assertDontSee('Tenant A collision activity marker');
        $this->assertTenantStateIsRevoked();

        $this->assertSame(
            ['Tenant A collision customer', 'Tenant A collision activity marker'],
            $this->tenantReadOnlyState($this->shopA, $tenantA['vehicle_id'], $tenantA['activity_id']),
        );
        $this->assertSame(
            ['Tenant B collision customer', 'Tenant B collision activity marker'],
            $this->tenantReadOnlyState($this->shopB, $tenantB['vehicle_id'], $tenantB['activity_id']),
        );
    }

    /**
     * Regression caught: resolving B's Filament edit page and Livewire update
     * to A discloses and renames A's colliding staff record and role pivot.
     */
    public function test_colliding_filament_user_update_only_targets_the_active_tenant_record(): void
    {
        $userAId = $this->seedStaff($this->shopA, 'Tenant A owner', 'Tenant A collision staff', 'staff-a');
        $userBId = $this->seedStaff($this->shopB, 'Tenant B owner', 'Tenant B collision staff', 'staff-b');

        $this->assertSame($userAId, $userBId);
        $this->loginTo($this->shopB);
        $editResponse = $this->get($this->tenantUrl($this->shopB, "/admin/users/{$userBId}/edit"))
            ->assertOk()
            ->assertSee('Tenant B collision staff')
            ->assertDontSee('Tenant A collision staff');
        $snapshot = $this->extractLivewireSnapshot($editResponse->getContent(), 'Pages\\EditUser');
        $this->assertTenantStateIsRevoked();

        $this->postLivewireUpdate($this->shopB, $snapshot, [
            'data.name' => 'Tenant B collision staff updated',
        ], 'save')->assertOk();
        $this->assertTenantStateIsRevoked();

        $this->assertSame(
            ['Tenant A collision staff', 'manager', 2],
            $this->tenantUserState($this->shopA, $userAId),
        );
        $this->assertSame(
            ['Tenant B collision staff updated', 'manager', 2],
            $this->tenantUserState($this->shopB, $userBId),
        );

        $this->logoutFrom($this->shopB);
        $this->loginTo($this->shopA);
        $editResponse = $this->get($this->tenantUrl($this->shopA, "/admin/users/{$userAId}/edit"));
        $this->assertSame(
            200,
            $editResponse->getStatusCode(),
            'Tenant A edit redirected to '.$editResponse->headers->get('Location'),
        );
        $editResponse
            ->assertSee('Tenant A collision staff')
            ->assertDontSee('Tenant B collision staff updated');
        $snapshot = $this->extractLivewireSnapshot($editResponse->getContent(), 'Pages\\EditUser');
        $this->assertTenantStateIsRevoked();

        $updateResponse = $this->postLivewireUpdate($this->shopA, $snapshot, [
            'data.name' => 'Tenant A collision staff updated',
        ], 'save');
        $this->assertSame(
            200,
            $updateResponse->getStatusCode(),
            'Tenant A Livewire update redirected to '.$updateResponse->headers->get('Location'),
        );
        $this->assertTenantStateIsRevoked();

        $this->assertSame(
            ['Tenant A collision staff updated', 'manager', 2],
            $this->tenantUserState($this->shopA, $userAId),
        );
        $this->assertSame(
            ['Tenant B collision staff updated', 'manager', 2],
            $this->tenantUserState($this->shopB, $userBId),
        );
    }

    /**
     * Regression caught: resolving B's Filament edit page and Livewire update
     * to A discloses and renames A's make while traversing A's models and links.
     */
    public function test_colliding_filament_vehicle_make_update_only_targets_the_active_tenant_catalogue(): void
    {
        $catalogueA = $this->seedVehicleCatalogue(
            $this->shopA,
            'Tenant A owner',
            'Tenant A collision make',
            'Tenant A collision model',
        );
        $catalogueB = $this->seedVehicleCatalogue(
            $this->shopB,
            'Tenant B owner',
            'Tenant B collision make',
            'Tenant B collision model',
        );

        $this->assertSame($catalogueA['make_id'], $catalogueB['make_id']);
        $this->assertSame($catalogueA['model_id'], $catalogueB['model_id']);
        $this->assertSame($catalogueA['compatibility_id'], $catalogueB['compatibility_id']);
        $this->loginTo($this->shopB);
        $editResponse = $this->get($this->tenantUrl($this->shopB, "/admin/vehicle-makes/{$catalogueB['make_id']}/edit"))
            ->assertOk()
            ->assertSee('Tenant B collision make')
            ->assertSee('Tenant B collision model')
            ->assertDontSee('Tenant A collision make')
            ->assertDontSee('Tenant A collision model');
        $snapshot = $this->extractLivewireSnapshot($editResponse->getContent(), 'Pages\\EditVehicleMake');
        $this->assertTenantStateIsRevoked();

        $this->postLivewireUpdate($this->shopB, $snapshot, [
            'data.name' => 'Tenant B collision make updated',
        ], 'save')->assertOk();
        $this->assertTenantStateIsRevoked();

        $this->assertSame(
            ['Tenant A collision make', 'Tenant A collision model', 1],
            $this->tenantVehicleCatalogueState($this->shopA, $catalogueA['make_id']),
        );
        $this->assertSame(
            ['Tenant B collision make updated', 'Tenant B collision model', 1],
            $this->tenantVehicleCatalogueState($this->shopB, $catalogueB['make_id']),
        );
    }

    private function createActiveTenant(string $slug): Shop
    {
        $shop = Shop::registerForProvisioning(
            name: str($slug)->headline()->toString(),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $this->tenantRoot.DIRECTORY_SEPARATOR.$slug.'.sqlite',
        );
        $this->createMigratedTenantDatabase($shop);
        $shop->markActive();

        return $shop;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function seedTenant(Shop $shop, callable $callback): mixed
    {
        return $this->manager->within($shop, $callback);
    }

    private function createOwner(string $name): User
    {
        return User::factory()->admin()->create([
            'name' => $name,
            'username' => 'owner',
            'password' => 'password',
        ]);
    }

    private function loginTo(Shop $shop): void
    {
        $this->post($this->tenantUrl($shop, '/login'), [
            'username' => 'owner',
            'password' => 'password',
        ])->assertRedirect();
        $this->assertTenantStateIsRevoked();
    }

    private function logoutFrom(Shop $shop): void
    {
        $this->post($this->tenantUrl($shop, '/logout'))->assertRedirect();
        $this->assertTenantStateIsRevoked();
        $this->flushSession();
    }

    private function tenantUrl(Shop $shop, string $path): string
    {
        return "https://{$shop->slug}.pos.example.test/".ltrim($path, '/');
    }

    /** @return array{string, string}|null */
    private function tenantItemState(Shop $shop, int $itemId): ?array
    {
        return $this->seedTenant($shop, static function () use ($itemId): ?array {
            $item = Item::query()->find($itemId);

            return $item === null ? null : [$item->name, $item->unit_cost];
        });
    }

    /** @return array{sale_id: int, line_id: int} */
    private function seedSale(
        Shop $shop,
        string $ownerName,
        string $invoiceNumber,
        string $customerName,
        string $lineName,
    ): array {
        return $this->seedTenant($shop, function () use ($ownerName, $invoiceNumber, $customerName, $lineName): array {
            $owner = $this->createOwner($ownerName);
            $sale = Sale::factory()->create([
                'cashier_id' => $owner->getKey(),
                'invoice_number' => $invoiceNumber,
                'customer_name' => $customerName,
                'total_amount' => 750,
            ]);
            $line = SaleItem::factory()->for($sale)->create([
                'item_name' => $lineName,
                'manually_charged_price' => 750,
            ]);

            return ['sale_id' => $sale->getKey(), 'line_id' => $line->getKey()];
        });
    }

    /** @return array{string, string, string}|null */
    private function tenantSaleState(Shop $shop, int $saleId): ?array
    {
        return $this->seedTenant($shop, static function () use ($saleId): ?array {
            $sale = Sale::query()->with('lines')->find($saleId);

            return $sale === null
                ? null
                : [$sale->invoice_number, $sale->customer_name, $sale->lines->sole()->item_name];
        });
    }

    private function seedExpense(Shop $shop, string $ownerName, string $description, int $amount): int
    {
        return $this->seedTenant($shop, function () use ($ownerName, $description, $amount): int {
            $owner = $this->createOwner($ownerName);

            return Expense::factory()->for($owner)->create([
                'category' => ExpenseCategory::Rent,
                'amount' => $amount,
                'description' => $description,
            ])->getKey();
        });
    }

    /** @return array{string, string, string}|null */
    private function tenantExpenseState(Shop $shop, int $expenseId): ?array
    {
        return $this->seedTenant($shop, static function () use ($expenseId): ?array {
            $expense = Expense::query()->find($expenseId);

            return $expense === null
                ? null
                : [$expense->description, $expense->amount, $expense->category->value];
        });
    }

    /** @return array{vehicle_id: int, activity_id: int} */
    private function seedCustomerVehicleAndActivity(
        Shop $shop,
        string $ownerName,
        string $customerName,
        string $description,
    ): array {
        return $this->seedTenant($shop, function () use ($ownerName, $customerName, $description): array {
            $owner = $this->createOwner($ownerName);
            $vehicle = CustomerVehicle::factory()->create(['customer_name' => $customerName]);
            $activity = ActivityLog::factory()->by($owner)->create([
                'action' => 'item.updated',
                'description' => $description,
            ]);

            return ['vehicle_id' => $vehicle->getKey(), 'activity_id' => $activity->getKey()];
        });
    }

    /** @return array{string, string} */
    private function tenantReadOnlyState(Shop $shop, int $vehicleId, int $activityId): array
    {
        return $this->seedTenant($shop, static fn (): array => [
            CustomerVehicle::query()->findOrFail($vehicleId)->customer_name,
            ActivityLog::query()->findOrFail($activityId)->description,
        ]);
    }

    private function seedStaff(Shop $shop, string $ownerName, string $staffName, string $username): int
    {
        return $this->seedTenant($shop, function () use ($ownerName, $staffName, $username): int {
            $this->createOwner($ownerName);

            return User::factory()->manager()->create([
                'name' => $staffName,
                'username' => $username,
            ])->getKey();
        });
    }

    /** @return array{string, string, int} */
    private function tenantUserState(Shop $shop, int $userId): array
    {
        return $this->seedTenant($shop, static function () use ($userId): array {
            $user = User::query()->findOrFail($userId);

            return [
                $user->name,
                (string) $user->roleName(),
                (int) DB::connection('tenant')->table('model_has_roles')->count(),
            ];
        });
    }

    /** @return array{make_id: int, model_id: int, compatibility_id: int} */
    private function seedVehicleCatalogue(
        Shop $shop,
        string $ownerName,
        string $makeName,
        string $modelName,
    ): array {
        return $this->seedTenant($shop, function () use ($ownerName, $makeName, $modelName): array {
            $this->createOwner($ownerName);
            $make = VehicleMake::factory()->create(['name' => $makeName]);
            $model = VehicleModel::factory()->for($make)->create(['name' => $modelName]);
            $item = Item::factory()->create(['name' => $makeName.' linked item', 'is_universal' => false]);
            $compatibility = $item->vehicleCompatibilities()->create([
                'vehicle_model_id' => $model->getKey(),
                'year_from' => 2015,
                'year_to' => 2020,
            ]);

            return [
                'make_id' => $make->getKey(),
                'model_id' => $model->getKey(),
                'compatibility_id' => $compatibility->getKey(),
            ];
        });
    }

    /** @return array{string, string, int} */
    private function tenantVehicleCatalogueState(Shop $shop, int $makeId): array
    {
        return $this->seedTenant($shop, static function () use ($makeId): array {
            $make = VehicleMake::query()->with('vehicleModels')->findOrFail($makeId);

            return [
                $make->name,
                $make->vehicleModels->sole()->name,
                (int) DB::connection('tenant')->table('item_vehicle_compatibilities')->count(),
            ];
        });
    }

    /** @param array<string, mixed> $updates */
    private function postLivewireUpdate(
        Shop $shop,
        string $snapshot,
        array $updates,
        string $method,
    ): TestResponse {
        return $this->withHeader('X-Livewire', 'true')
            ->postJson($this->tenantUrl($shop, EndpointResolver::updatePath()), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => $updates,
                    'calls' => [[
                        'method' => $method,
                        'params' => [],
                        'path' => '',
                    ]],
                ]],
            ]);
    }

    private function extractLivewireSnapshot(string $html, string $componentName): string
    {
        $matched = preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

        $this->assertGreaterThan(0, $matched, 'The rendered page did not contain a Livewire snapshot.');

        $componentNames = [];

        foreach ($matches[1] as $encodedSnapshot) {
            $snapshot = html_entity_decode($encodedSnapshot, ENT_QUOTES | ENT_HTML5);
            $payload = json_decode($snapshot, true);

            $actualName = is_array($payload) ? (string) data_get($payload, 'memo.name') : '';
            $componentNames[] = $actualName;

            if (str_contains($actualName, $componentName)) {
                return $snapshot;
            }
        }

        $this->fail("The rendered page did not contain the {$componentName} Livewire snapshot. Found: ".implode(', ', $componentNames));

        throw new RuntimeException('Unreachable.');
    }

    private function assertTenantStateIsRevoked(): void
    {
        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
        $this->assertArrayNotHasKey('tenant', DB::getConnections());
    }

    private function newTemporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.Str::uuid();

        if (! File::makeDirectory($directory, 0700, true)) {
            throw new RuntimeException('Unable to create cross-tenant isolation test directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
