<?php

namespace Tests\Feature\Platform;

use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Filament\Platform\Resources\Shops\Pages\CreateShop;
use App\Filament\Platform\Resources\Shops\Pages\ListShops;
use App\Filament\Platform\Resources\Shops\Pages\ViewShop;
use App\Filament\Platform\Resources\Shops\ShopResource;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopOwner;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\Tenancy\Provisioning\DatabaseProvisioner;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;
use RuntimeException;

class ShopManagementTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
    }

    public function test_platform_administrator_can_create_and_provision_a_shop(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $temporaryPassword = 'temporary-owner-password';

        Livewire::actingAs($platformUser, 'platform')
            ->test(CreateShop::class)
            ->fillForm([
                'name' => 'North Workshop',
                'slug' => 'north-workshop',
                'timezone' => 'Asia/Karachi',
                'currency' => 'PKR',
                'owner_name' => 'North Owner',
                'owner_username' => 'north-owner',
                'owner_email' => 'owner@example.test',
                'temporary_owner_password' => $temporaryPassword,
                'temporary_owner_password_confirmation' => $temporaryPassword,
                'initial_feature_keys' => ['scripts', 'workshop'],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Shop provisioned')
            ->assertDontSee($temporaryPassword);

        $shop = Shop::query()->where('slug', 'north-workshop')->firstOrFail();

        $this->assertSame(ShopStatus::Active, $shop->status);
        $this->assertFileExists((string) $shop->database_name);
        $this->assertDatabaseHas('shop_owners', [
            'shop_id' => $shop->getKey(),
            'name' => 'North Owner',
            'username' => 'north-owner',
            'email' => 'owner@example.test',
        ], 'central');
        $this->assertDatabaseHas('shop_health_snapshots', [
            'shop_id' => $shop->getKey(),
            'migration_status' => 'current',
        ], 'central');
        $this->assertSame(
            [
                $platformUser->getKey(),
                $platformUser->getKey(),
                $platformUser->getKey(),
            ],
            $shop->lifecycleActivities()
                ->whereIn('event', [
                    ShopLifecycleEvent::ProvisioningStarted,
                    ShopLifecycleEvent::TenantInstallationAuthorized,
                    ShopLifecycleEvent::ProvisioningSucceeded,
                ])
                ->orderBy('id')
                ->pluck('platform_user_id')
                ->all(),
        );
        $this->assertFalse(resolve(TenantContext::class)->initialized());
    }

    public function test_failed_web_provisioning_is_retained_with_a_safe_actionable_notification(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $temporaryPassword = 'do-not-expose-this-password';
        $this->mock(DatabaseProvisioner::class)
            ->shouldReceive('provision')
            ->once()
            ->andThrow(new RuntimeException('private infrastructure failure'));

        Livewire::actingAs($platformUser, 'platform')
            ->test(CreateShop::class)
            ->fillForm([
                'name' => 'Failed Web Workshop',
                'slug' => 'failed-web-workshop',
                'timezone' => 'Asia/Karachi',
                'currency' => 'PKR',
                'owner_name' => 'Failed Owner',
                'owner_username' => 'failed-owner',
                'owner_email' => 'failed@example.test',
                'temporary_owner_password' => $temporaryPassword,
                'temporary_owner_password_confirmation' => $temporaryPassword,
                'initial_feature_keys' => ['scripts'],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertDontSee($temporaryPassword)
            ->assertDontSee('private infrastructure failure');

        $shop = Shop::query()->where('slug', 'failed-web-workshop')->firstOrFail();

        $this->assertSame(ShopStatus::Failed, $shop->status);
        $this->assertDatabaseHas('shop_lifecycle_activities', [
            'shop_id' => $shop->getKey(),
            'platform_user_id' => $platformUser->getKey(),
            'event' => ShopLifecycleEvent::ProvisioningFailed->value,
        ], 'central');
        Notification::assertNotified(
            Notification::make()
                ->danger()
                ->title('Shop provisioning failed')
                ->body(
                    'The tenant database could not be provisioned. Review the application log code and retry. '
                    .'Stage: database. Code: DATABASE_PROVISION_FAILED.',
                )
                ->actions([
                    Action::make('reviewFailedShop')
                        ->label('Review failed shop')
                        ->url(ShopResource::getUrl('view', ['record' => $shop]))
                        ->markAsRead(),
                ])
                ->persistent(),
        );
    }

    public function test_shop_list_uses_safe_status_badges_and_redacts_database_details(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $privateDatabase = rtrim((string) config('database.tenant_sqlite_root'), '/\\')
            .DIRECTORY_SEPARATOR.'private-target.sqlite';
        $privateUsername = 'private-database-user';
        $privatePassword = 'private-database-password';
        $failedShop = Shop::registerForProvisioning(
            name: 'Failed Workshop',
            slug: 'failed-workshop',
            databaseDriver: 'sqlite',
            databaseName: $privateDatabase,
            databaseUsername: $privateUsername,
            databasePassword: $privatePassword,
        );
        $failedShop->markProvisioningFailed('Provisioning stopped safely.');
        $shops = [
            Shop::factory()->create(['name' => 'Active Workshop', 'status' => ShopStatus::Active]),
            Shop::factory()->create(['name' => 'Provisioning Workshop', 'status' => ShopStatus::Provisioning]),
            Shop::factory()->create(['name' => 'Suspended Workshop', 'status' => ShopStatus::Suspended]),
            $failedShop->fresh(),
        ];
        $expectedColors = [
            ShopStatus::Active->value => 'success',
            ShopStatus::Provisioning->value => 'warning',
            ShopStatus::Suspended->value => 'gray',
            ShopStatus::Failed->value => 'danger',
        ];

        $list = Livewire::actingAs($platformUser, 'platform')
            ->test(ListShops::class)
            ->assertCanSeeTableRecords($shops)
            ->assertDontSee($privateDatabase)
            ->assertDontSee($privateUsername)
            ->assertDontSee($privatePassword);

        foreach ($shops as $shop) {
            $list->assertTableColumnExists(
                'status',
                static fn (TextColumn $column): bool => $column->getColor($shop->status)
                    === $expectedColors[$shop->status->value],
                $shop,
            );
        }

        Livewire::actingAs($platformUser, 'platform')
            ->test(ViewShop::class, ['record' => $failedShop->getKey()])
            ->assertDontSee($privateDatabase)
            ->assertDontSee($privateUsername)
            ->assertDontSee($privatePassword);
    }

    public function test_shop_list_counts_effective_features_when_legacy_rows_are_missing(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create([
            'name' => 'Legacy Feature Workshop',
            'status' => ShopStatus::Active,
        ]);
        $shop->features()->create([
            'module_key' => 'scripts',
            'enabled' => false,
        ]);
        $expectedCount = resolve(ModuleRegistry::class)
            ->all()
            ->reject(static fn (Module $module): bool => $module->isCore())
            ->filter(static fn (Module $module): bool => $module->key() !== 'scripts')
            ->count();

        Livewire::actingAs($platformUser, 'platform')
            ->test(ListShops::class)
            ->assertTableColumnStateSet('enabled_features_count', $expectedCount, $shop);
    }

    public function test_platform_administrator_can_suspend_and_reactivate_a_shop(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create([
            'name' => 'Lifecycle Workshop',
            'status' => ShopStatus::Active,
        ]);

        $suspendPage = Livewire::actingAs($platformUser, 'platform')
            ->test(ListShops::class)
            ->mountTableAction('suspend', $shop);

        $this->assertSame(
            'Staff cannot sign in or use shop operations until this shop is reactivated.',
            $suspendPage->instance()->getMountedAction()->getModalDescription(),
        );

        $suspendPage->callMountedTableAction()
            ->assertNotified('Shop suspended');

        $this->assertSame(ShopStatus::Suspended, $shop->fresh()->status);
        $this->assertDatabaseHas('shop_lifecycle_activities', [
            'shop_id' => $shop->getKey(),
            'platform_user_id' => $platformUser->getKey(),
            'event' => ShopLifecycleEvent::Suspended->value,
        ], 'central');

        Livewire::actingAs($platformUser, 'platform')
            ->test(ListShops::class)
            ->callTableAction('reactivate', $shop->fresh())
            ->assertNotified('Shop reactivated');

        $this->assertSame(ShopStatus::Active, $shop->fresh()->status);
        $this->assertDatabaseHas('shop_lifecycle_activities', [
            'shop_id' => $shop->getKey(),
            'platform_user_id' => $platformUser->getKey(),
            'event' => ShopLifecycleEvent::Reactivated->value,
        ], 'central');
    }

    public function test_platform_administrator_can_retry_failed_provisioning(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $database = rtrim((string) config('database.tenant_sqlite_root'), '/\\')
            .DIRECTORY_SEPARATOR.'retry-workshop.sqlite';
        $shop = Shop::registerForProvisioning(
            name: 'Retry Workshop',
            slug: 'retry-workshop',
            databaseDriver: 'sqlite',
            databaseName: $database,
        );
        ShopOwner::query()->create([
            'shop_id' => $shop->getKey(),
            'name' => 'Retry Owner',
            'username' => 'retry-owner',
            'email' => 'retry@example.test',
            'is_active' => true,
        ]);
        $shop->markProvisioningFailed('A safe failure that can be retried.');
        $temporaryPassword = 'retry-owner-password';

        Livewire::actingAs($platformUser, 'platform')
            ->test(ListShops::class)
            ->callTableAction('retryProvisioning', $shop->fresh(), [
                'temporary_owner_password' => $temporaryPassword,
            ])
            ->assertNotified('Shop provisioned')
            ->assertDontSee($temporaryPassword);

        $this->assertSame(ShopStatus::Active, $shop->fresh()->status);
        $this->assertFileExists($database);
        $this->assertSame(
            [
                $platformUser->getKey(),
                $platformUser->getKey(),
                $platformUser->getKey(),
            ],
            $shop->lifecycleActivities()
                ->whereIn('event', [
                    ShopLifecycleEvent::ProvisioningStarted,
                    ShopLifecycleEvent::TenantInstallationAuthorized,
                    ShopLifecycleEvent::ProvisioningSucceeded,
                ])
                ->orderBy('id')
                ->pluck('platform_user_id')
                ->all(),
        );
        $this->assertFalse(resolve(TenantContext::class)->initialized());
    }

    public function test_shop_create_and_detail_schemas_use_stable_responsive_columns(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();
        $createPage = Livewire::actingAs($platformUser, 'platform')->test(CreateShop::class);

        foreach ($createPage->instance()->form->getComponents() as $component) {
            $this->assertInstanceOf(Section::class, $component);
            $this->assertSame(['default' => 1, 'lg' => 2], $component->getColumns());
        }

        $viewPage = Livewire::actingAs($platformUser, 'platform')
            ->test(ViewShop::class, ['record' => $shop->getKey()]);
        $detailComponents = $viewPage->instance()->getSchema('infolist')->getComponents();

        $this->assertCount(1, $detailComponents);
        $this->assertInstanceOf(Grid::class, $detailComponents[0]);
        $this->assertSame(['default' => 1, 'lg' => 2], $detailComponents[0]->getColumns());
    }

    public function test_shop_detail_exposes_all_required_statistics_periods(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();

        Livewire::actingAs($platformUser, 'platform')
            ->test(ViewShop::class, ['record' => $shop->getKey()])
            ->assertSee('Today')
            ->assertSee('This week')
            ->assertSee('This month');
    }
}
