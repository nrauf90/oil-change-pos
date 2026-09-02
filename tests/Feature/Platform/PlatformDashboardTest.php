<?php

namespace Tests\Feature\Platform;

use App\Enums\ShopStatus;
use App\Filament\Platform\Pages\PlatformDashboard;
use App\Filament\Platform\Resources\PlatformUsers\PlatformUserResource;
use App\Filament\Platform\Widgets\ShopStatusOverview;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

class PlatformDashboardTest extends PlatformTestCase
{
    public function test_dashboard_displays_shop_counts_by_status_in_lifecycle_order(): void
    {
        $this->assertTrue(class_exists(ShopStatusOverview::class));

        $platformUser = PlatformUser::factory()->create();
        Shop::factory()->count(2)->create(['status' => ShopStatus::Active]);
        Shop::factory()->create(['status' => ShopStatus::Provisioning]);
        Shop::factory()->create(['status' => ShopStatus::Failed]);
        Shop::factory()->create(['status' => ShopStatus::Suspended]);
        Shop::factory()->create(['status' => ShopStatus::Active])->delete();

        Livewire::actingAs($platformUser, 'platform')
            ->test(ShopStatusOverview::class)
            ->assertSeeInOrder([
                'Active',
                '2',
                'Provisioning',
                '1',
                'Failed',
                '1',
                'Suspended',
                '1',
            ]);
    }

    public function test_dashboard_displays_zero_for_statuses_without_shops(): void
    {
        $this->assertTrue(class_exists(ShopStatusOverview::class));

        $platformUser = PlatformUser::factory()->create();

        Livewire::actingAs($platformUser, 'platform')
            ->test(ShopStatusOverview::class)
            ->assertSeeInOrder([
                'Active',
                '0',
                'Provisioning',
                '0',
                'Failed',
                '0',
                'Suspended',
                '0',
            ]);
    }

    public function test_dashboard_uses_the_required_responsive_card_layout(): void
    {
        $this->assertTrue(class_exists(ShopStatusOverview::class));

        $widget = new ShopStatusOverview;

        $this->assertSame(['default' => 1, 'md' => 2, 'xl' => 4], $widget->getColumns());
        $this->assertSame(1, (new PlatformDashboard)->getColumns());
    }

    public function test_status_cards_link_to_the_matching_shop_filter_when_shop_management_is_available(): void
    {
        $this->assertTrue(class_exists(ShopStatusOverview::class));

        Route::get('/platform/test-shops', static fn (): string => '')->name(
            'filament.platform.resources.shops.index',
        );
        $platformUser = PlatformUser::factory()->create();
        $component = Livewire::actingAs($platformUser, 'platform')->test(ShopStatusOverview::class);

        foreach (ShopStatus::cases() as $status) {
            $url = route('filament.platform.resources.shops.index', [
                'tableFilters' => ['status' => ['value' => $status->value]],
            ]);

            $component->assertSeeHtml('href="'.e($url).'"');
        }
    }

    public function test_dashboard_offers_the_shop_creation_action_when_shop_management_is_available(): void
    {
        Route::get('/platform/test-shops/create', static fn (): string => '')->name(
            'filament.platform.resources.shops.create',
        );
        $platformUser = PlatformUser::factory()->create();

        Livewire::actingAs($platformUser, 'platform')
            ->test(PlatformDashboard::class)
            ->assertActionVisible('createShop')
            ->assertActionHasLabel('createShop', 'Create shop')
            ->assertActionHasIcon('createShop', Heroicon::OutlinedPlus)
            ->assertActionHasUrl('createShop', route('filament.platform.resources.shops.create'));
    }

    public function test_platform_panel_discovers_only_the_platform_dashboard_resource_and_widget(): void
    {
        $this->assertTrue(class_exists(ShopStatusOverview::class));

        $panel = Filament::getPanels()['platform'];
        $widgets = collect($panel->getWidgets())
            ->map(static fn (mixed $widget): string => is_string($widget) ? $widget : $widget->widget)
            ->all();

        $this->assertContains(PlatformDashboard::class, $panel->getPages());
        $this->assertContains(PlatformUserResource::class, $panel->getResources());
        $this->assertContains(ShopStatusOverview::class, $widgets);
        $this->assertNotContains(AccountWidget::class, $widgets);

        foreach ([...$panel->getPages(), ...$panel->getResources(), ...$widgets] as $component) {
            $this->assertStringStartsWith('App\\Filament\\Platform\\', $component);
        }
    }
}
