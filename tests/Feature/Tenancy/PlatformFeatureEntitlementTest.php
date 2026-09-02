<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Tenancy\RecordShopLifecycleActivity;
use App\Enums\ShopLifecycleEvent;
use App\Filament\Pages\ModuleSwitchboard;
use App\Filament\Platform\Resources\Shops\Pages\ViewShop;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopFeature;
use App\Models\Expense;
use App\Models\ModuleSetting;
use App\Models\User;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PlatformFeatureEntitlementTest extends TestCase
{
    use RefreshDatabase;

    /** ViewShop reconnects the per-test tenant, so only central uses a test transaction. */
    protected array $connectionsToTransact = ['central'];

    protected function setUp(): void
    {
        parent::setUp();

        $adminPanel = Filament::getPanels()['admin'] ?? null;

        if ($adminPanel !== null) {
            Filament::setCurrentPanel($adminPanel);
        }
    }

    public function test_platform_off_feature_is_not_effectively_enabled(): void
    {
        ModuleSetting::query()->create(['key' => 'scripts', 'enabled' => true]);
        $this->setPlatformFeature('scripts', false);

        $this->assertFalse($this->registry()->enabled('scripts'));
    }

    public function test_missing_platform_row_uses_the_module_default(): void
    {
        $this->assertDatabaseMissing('shop_features', [
            'shop_id' => $this->currentShop()->getKey(),
            'module_key' => 'workshop',
        ], 'central');

        $this->assertTrue($this->registry()->enabled('workshop'));
    }

    public function test_missing_platform_row_uses_a_default_off_module_default(): void
    {
        $registry = $this->registry();
        $registry->register(new TestDefaultOffModule);
        ModuleSetting::query()->create(['key' => 'test-default-off', 'enabled' => true]);
        $registry->flush();

        $this->assertFalse($registry->enabled('test-default-off'));
    }

    public function test_core_module_remains_enabled_when_both_stored_states_are_off(): void
    {
        ModuleSetting::query()->create(['key' => 'sales', 'enabled' => false]);
        $this->setPlatformFeature('sales', false);

        $this->assertTrue($this->registry()->enabled('sales'));
    }

    public function test_tenant_off_preference_remains_effectively_off_when_platform_allows_feature(): void
    {
        ModuleSetting::query()->create(['key' => 'expenses', 'enabled' => false]);
        $this->setPlatformFeature('expenses', true);

        $this->assertFalse($this->registry()->enabled('expenses'));
    }

    public function test_platform_lookup_failure_with_current_shop_fails_closed(): void
    {
        ModuleSetting::query()->create(['key' => 'scripts', 'enabled' => true]);
        Schema::connection('central')->drop('shop_features');
        $this->registry()->flush();

        $this->assertFalse($this->registry()->enabled('scripts'));
    }

    public function test_platform_off_feature_route_returns_404(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->setPlatformFeature('scripts', false);

        $this->get(route('scripts.index'))->assertNotFound();
    }

    public function test_platform_off_feature_is_hidden_from_navigation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->setPlatformFeature('expenses', false);

        $labels = collect($this->registry()->navigationFor($admin))->pluck('label')->all();

        $this->assertNotContains('Expenses', $labels);
    }

    public function test_owner_cannot_override_platform_off_feature_from_switchboard(): void
    {
        $owner = User::factory()->admin()->create();
        ModuleSetting::query()->create(['key' => 'scripts', 'enabled' => false]);
        $this->setPlatformFeature('scripts', false);

        Livewire::actingAs($owner)
            ->test(ModuleSwitchboard::class)
            ->call('toggle', 'scripts');

        $this->assertDatabaseHas('modules', [
            'key' => 'scripts',
            'enabled' => false,
        ], 'tenant');
    }

    public function test_tenant_cannot_enable_module_while_dependency_is_off(): void
    {
        $registry = $this->registry();
        $registry->register(new TestDependencyModule);
        $registry->register(new TestDependentModule);
        ModuleSetting::query()->create(['key' => 'test-dependency', 'enabled' => false]);
        ModuleSetting::query()->create(['key' => 'test-dependent', 'enabled' => false]);
        $registry->flush();

        $registry->setEnabled('test-dependent', true);

        $this->assertDatabaseHas('modules', [
            'key' => 'test-dependent',
            'enabled' => false,
        ], 'tenant');
        $this->assertFalse($registry->enabled('test-dependent'));
    }

    /** @param array<int, mixed> $featureKeys */
    #[DataProvider('invalidForgedFeatureValues')]
    public function test_invalid_forged_feature_values_do_not_mutate_entitlements(array $featureKeys): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->currentShop();

        $this->platformShopPage($platformUser, $shop)
            ->callAction('manageFeatures', ['feature_keys' => $featureKeys])
            ->assertHasActionErrors();

        $this->assertDatabaseCount('shop_features', 0, 'central');
        $this->assertSame(0, $shop->lifecycleActivities()
            ->whereIn('event', [
                ShopLifecycleEvent::FeatureEnabled,
                ShopLifecycleEvent::FeatureDisabled,
            ])
            ->count());
    }

    public function test_duplicate_forged_feature_values_do_not_mutate_entitlements(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->currentShop();

        $this->platformShopPage($platformUser, $shop)
            ->callAction('manageFeatures', ['feature_keys' => ['scripts', 'scripts']])
            ->assertNotified('Features were not updated');

        $this->assertDatabaseCount('shop_features', 0, 'central');
    }

    public function test_platform_rejects_a_feature_whose_optional_dependency_is_off(): void
    {
        $registry = $this->registry();
        $registry->register(new TestDependencyModule);
        $registry->register(new TestDependentModule);
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->currentShop();

        $this->platformShopPage($platformUser, $shop)
            ->callAction('manageFeatures', [
                'feature_keys' => ['reports', 'expenses', 'scripts', 'workshop', 'test-dependent'],
            ])
            ->assertNotified('Features were not updated');

        $this->assertDatabaseCount('shop_features', 0, 'central');
    }

    public function test_actor_deactivated_after_action_authorization_cannot_mutate_entitlements(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->currentShop();
        $page = $this->platformShopPage($platformUser, $shop);
        $deactivated = false;
        $outerTransactionLevel = DB::connection('central')->transactionLevel();

        Event::listen(
            TransactionBeginning::class,
            static function (TransactionBeginning $event) use (
                &$deactivated,
                $outerTransactionLevel,
                $platformUser,
            ): void {
                if ($deactivated
                    || $event->connectionName !== 'central'
                    || $event->connection->transactionLevel() <= $outerTransactionLevel) {
                    return;
                }

                DB::connection('central')
                    ->table('platform_users')
                    ->where('id', $platformUser->getKey())
                    ->update(['is_active' => false]);
                $deactivated = true;
            },
        );

        $this->callDuringConcurrentDeactivation(static fn () => $page->callAction('manageFeatures', [
            'feature_keys' => ['reports', 'expenses', 'workshop'],
        ]));

        $this->assertTrue($deactivated, 'The test must deactivate the actor as the write transaction begins.');
        $this->assertDatabaseMissing('shop_features', [
            'shop_id' => $shop->getKey(),
            'module_key' => 'scripts',
        ], 'central');
        $this->assertSame(0, $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::FeatureDisabled)
            ->count());
    }

    public function test_feature_write_rolls_back_when_the_central_audit_fails(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->currentShop();
        $this->mock(RecordShopLifecycleActivity::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Simulated lifecycle audit failure.'));

        try {
            $this->platformShopPage($platformUser, $shop)
                ->callAction('manageFeatures', [
                    'feature_keys' => ['reports', 'expenses', 'workshop'],
                ]);

            $this->fail('The simulated lifecycle audit failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated lifecycle audit failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('shop_features', [
            'shop_id' => $shop->getKey(),
            'module_key' => 'scripts',
        ], 'central');
        $this->assertSame(0, $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::FeatureDisabled)
            ->count());
    }

    public function test_feature_update_locks_the_shop_before_reading_entitlements(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->currentShop();
        $page = $this->platformShopPage($platformUser, $shop);
        $centralOperations = [];

        Event::listen(TransactionBeginning::class, static function (TransactionBeginning $event) use (
            &$centralOperations,
        ): void {
            if ($event->connectionName === 'central') {
                $centralOperations[] = 'transaction';
            }
        });
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$centralOperations): void {
            if ($event->connectionName !== 'central'
                || ! str_starts_with(strtolower(trim($event->sql)), 'select')) {
                return;
            }

            $sql = strtolower($event->sql);

            if (str_contains($sql, 'from "shops"')) {
                $centralOperations[] = 'shop';
            } elseif (str_contains($sql, 'from "shop_features"')) {
                $centralOperations[] = 'features';
            }
        });

        $page->callAction('manageFeatures', [
            'feature_keys' => ['reports', 'expenses', 'workshop'],
        ])->assertNotified('Features updated');

        $transactionIndex = collect($centralOperations)->search(
            static fn (string $operation): bool => $operation === 'transaction',
        );
        $shopReadIndex = collect($centralOperations)->search(
            static fn (string $operation, int $index): bool => is_int($transactionIndex)
                && $index > $transactionIndex
                && $operation === 'shop',
        );
        $featureReadIndex = collect($centralOperations)->search(
            static fn (string $operation, int $index): bool => is_int($shopReadIndex)
                && $index > $shopReadIndex
                && $operation === 'features',
        );

        $this->assertIsInt($transactionIndex, 'The update must use a central transaction.');
        $this->assertIsInt($shopReadIndex, 'The update must acquire the per-shop serialization row.');
        $this->assertIsInt($featureReadIndex, 'The update must read current entitlements inside the lock.');
    }

    public function test_platform_disable_and_reenable_preserves_module_owned_tenant_data(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->currentShop();
        $expense = Expense::factory()->create(['description' => 'Preserve this expense']);

        $this->platformShopPage($platformUser, $shop)
            ->callAction('manageFeatures', [
                'feature_keys' => ['reports', 'scripts', 'workshop'],
            ])
            ->assertNotified('Features updated');

        $this->assertTenantExpensePreserved($shop, $expense);

        $this->platformShopPage($platformUser, $shop)
            ->callAction('manageFeatures', [
                'feature_keys' => ['reports', 'expenses', 'scripts', 'workshop'],
            ])
            ->assertNotified('Features updated');

        $this->assertTenantExpensePreserved($shop, $expense);
    }

    public function test_platform_disable_and_reenable_preserves_tenant_data_and_creates_safe_audits(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = $this->currentShop();
        $tenantSetting = ModuleSetting::query()->create(['key' => 'scripts', 'enabled' => true]);

        $this->platformShopPage($platformUser, $shop)
            ->callAction('manageFeatures', [
                'feature_keys' => ['reports', 'expenses', 'workshop'],
            ])
            ->assertNotified('Features updated');

        $this->assertDatabaseHas('shop_features', [
            'shop_id' => $shop->getKey(),
            'module_key' => 'scripts',
            'enabled' => false,
        ], 'central');
        $this->assertTenantSettingPreserved($shop, $tenantSetting);

        $disabledAudit = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::FeatureDisabled)
            ->firstOrFail();

        $this->assertSame($platformUser->getKey(), $disabledAudit->platform_user_id);
        $this->assertSame([
            'module_key' => 'scripts',
            'reason_code' => 'platform_action',
        ], $disabledAudit->metadata);

        $this->platformShopPage($platformUser, $shop)
            ->callAction('manageFeatures', [
                'feature_keys' => ['reports', 'expenses', 'scripts', 'workshop'],
            ])
            ->assertNotified('Features updated');

        $this->assertDatabaseHas('shop_features', [
            'shop_id' => $shop->getKey(),
            'module_key' => 'scripts',
            'enabled' => true,
        ], 'central');
        $this->assertTenantSettingPreserved($shop, $tenantSetting);

        $enabledAudit = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::FeatureEnabled)
            ->firstOrFail();

        $this->assertSame([
            'module_key' => 'scripts',
            'reason_code' => 'platform_action',
        ], $enabledAudit->metadata);
        $this->assertStringNotContainsString(
            'secret',
            strtolower((string) json_encode([
                $disabledAudit->metadata,
                $enabledAudit->metadata,
            ], JSON_THROW_ON_ERROR)),
        );
    }

    private function registry(): ModuleRegistry
    {
        return resolve(ModuleRegistry::class);
    }

    private function currentShop(): Shop
    {
        return resolve(TenantContext::class)->shop();
    }

    private function setPlatformFeature(string $moduleKey, bool $enabled): void
    {
        ShopFeature::query()->updateOrCreate(
            [
                'shop_id' => $this->currentShop()->getKey(),
                'module_key' => $moduleKey,
            ],
            ['enabled' => $enabled],
        );
        $this->registry()->flush();
    }

    private function platformShopPage(PlatformUser $platformUser, Shop $shop): mixed
    {
        Filament::setCurrentPanel(Filament::getPanels()['platform']);

        return Livewire::actingAs($platformUser, 'platform')
            ->test(ViewShop::class, ['record' => $shop->getKey()]);
    }

    private function assertTenantSettingPreserved(Shop $shop, ModuleSetting $setting): void
    {
        resolve(TenantConnectionManager::class)->within($shop, function () use ($setting): void {
            $this->assertDatabaseHas('modules', [
                'id' => $setting->getKey(),
                'key' => 'scripts',
                'enabled' => true,
            ], 'tenant');
        });
    }

    private function assertTenantExpensePreserved(Shop $shop, Expense $expense): void
    {
        resolve(TenantConnectionManager::class)->within($shop, function () use ($expense): void {
            $this->assertDatabaseHas('expenses', [
                'id' => $expense->getKey(),
                'description' => 'Preserve this expense',
            ], 'tenant');
        });
    }

    private function callDuringConcurrentDeactivation(callable $action): void
    {
        try {
            $action();
        } catch (AuthorizationException) {
            return;
        } catch (InvalidArgumentException $exception) {
            $this->assertStringStartsWith('Invalid Livewire snapshot structure:', $exception->getMessage());
        }
    }

    /** @return array<string, array{array<int, mixed>}> */
    public static function invalidForgedFeatureValues(): array
    {
        return [
            'core module' => [['sales']],
            'unregistered module' => [['ghost-feature']],
            'non-string value' => [[123]],
        ];
    }
}

final class TestDefaultOffModule extends Module
{
    public function key(): string
    {
        return 'test-default-off';
    }

    public function title(): string
    {
        return 'Test default off';
    }

    public function description(): string
    {
        return 'Default-off fixture.';
    }

    public function permissions(): array
    {
        return [];
    }

    public function enabledByDefault(): bool
    {
        return false;
    }
}

final class TestDependencyModule extends Module
{
    public function key(): string
    {
        return 'test-dependency';
    }

    public function title(): string
    {
        return 'Test dependency';
    }

    public function description(): string
    {
        return 'Dependency fixture.';
    }

    public function permissions(): array
    {
        return [];
    }
}

final class TestDependentModule extends Module
{
    public function key(): string
    {
        return 'test-dependent';
    }

    public function title(): string
    {
        return 'Test dependent';
    }

    public function description(): string
    {
        return 'Dependent fixture.';
    }

    public function permissions(): array
    {
        return [];
    }

    public function dependsOn(): array
    {
        return ['test-dependency'];
    }
}
