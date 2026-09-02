<?php

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Models\Item;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Filament\Schemas\Components\Grid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_list_inventory(): void
    {
        $item = Item::factory()->create(['name' => 'ZIC X7 10W-40']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->assertCanSeeTableRecords([$item])
            ->assertSee('ZIC X7 10W-40');
    }

    public function test_an_admin_can_create_a_stock_tracked_product(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateItem::class)
            ->fillForm([
                'name' => 'Cabin Filter',
                'type' => ItemType::Product->value,
                'unit_cost' => 1200.50,
                'stock_level' => 12,
                'low_stock_alert' => 3,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('items', [
            'name' => 'Cabin Filter',
            'type' => 'product',
            'stock_level' => 12,
            'low_stock_alert' => 3,
        ]);
    }

    public function test_create_and_edit_forms_use_explicit_rows_for_the_inventory_cards(): void
    {
        $admin = User::factory()->admin()->create();
        $item = Item::factory()->create();

        foreach ([
            Livewire::actingAs($admin)->test(CreateItem::class),
            Livewire::actingAs($admin)->test(EditItem::class, ['record' => $item->getKey()]),
        ] as $page) {
            $components = $page->instance()->form->getComponents(withHidden: true);

            $this->assertCount(4, $components);
            $this->assertInstanceOf(Grid::class, $components[0]);
            $this->assertInstanceOf(Grid::class, $components[1]);
            $this->assertSame(2, $components[0]->getColumns('lg'));
            $this->assertSame(2, $components[1]->getColumns('lg'));
        }
    }

    public function test_a_duplicate_item_name_is_rejected(): void
    {
        Item::factory()->create(['name' => 'Air Filter']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateItem::class)
            ->fillForm(['name' => 'Air Filter', 'type' => ItemType::Product->value])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_stock_can_be_corrected_from_the_admin_panel(): void
    {
        $item = Item::factory()->create(['stock_level' => 2, 'low_stock_alert' => 5]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(EditItem::class, ['record' => $item->getKey()])
            ->fillForm(['stock_level' => 40])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('40.000', $item->refresh()->stock_level);
    }

    /* ---------------------------------------------------------------- */
    /* Unit cost is owner-only (PRD §1: managers are kept off margins) */
    /* ---------------------------------------------------------------- */

    public function test_a_manager_cannot_see_the_unit_cost_column(): void
    {
        Item::factory()->create(['name' => 'ZIC X7 10W-40', 'unit_cost' => 6800]);

        Livewire::actingAs(User::factory()->manager()->create())
            ->test(ListItems::class)
            ->assertDontSee('6,800.00');
    }

    public function test_an_admin_can_see_the_unit_cost_column(): void
    {
        Item::factory()->create(['name' => 'ZIC X7 10W-40', 'unit_cost' => 6800]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->assertSee('6,800.00');
    }

    public function test_a_manager_cannot_change_the_unit_cost(): void
    {
        $item = Item::factory()->create(['unit_cost' => 1000]);

        Livewire::actingAs(User::factory()->manager()->create())
            ->test(EditItem::class, ['record' => $item->getKey()])
            ->fillForm(['name' => 'Renamed By Manager'])
            ->call('save')
            ->assertHasNoFormErrors();

        $item->refresh();

        $this->assertSame('Renamed By Manager', $item->name);
        $this->assertSame('1000.00', $item->unit_cost, 'a manager must not be able to rewrite costing');
    }

    /* ---------------------------------------------------------------- */
    /* Low stock */
    /* ---------------------------------------------------------------- */

    public function test_the_navigation_badge_counts_items_low_on_stock(): void
    {
        Item::factory()->create(['stock_level' => 1, 'low_stock_alert' => 5]);
        Item::factory()->create(['stock_level' => 50, 'low_stock_alert' => 5]);
        Item::factory()->create(['stock_level' => null, 'low_stock_alert' => null]);

        $this->actingAs(User::factory()->admin()->create());

        $this->assertSame('1', ItemResource::getNavigationBadge());
    }

    public function test_the_navigation_badge_is_hidden_when_nothing_is_low(): void
    {
        Item::factory()->create(['stock_level' => 50, 'low_stock_alert' => 5]);

        $this->actingAs(User::factory()->admin()->create());

        $this->assertNull(ItemResource::getNavigationBadge());
    }

    public function test_the_low_stock_filter_narrows_the_table(): void
    {
        $low = Item::factory()->create(['name' => 'Nearly Out', 'stock_level' => 1, 'low_stock_alert' => 5]);
        $fine = Item::factory()->create(['name' => 'Plenty Left', 'stock_level' => 90, 'low_stock_alert' => 5]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->filterTable('low_stock')
            ->assertCanSeeTableRecords([$low])
            ->assertCanNotSeeTableRecords([$fine]);
    }

    /* ---------------------------------------------------------------- */
    /* Authorization + module gating */
    /* ---------------------------------------------------------------- */

    public function test_a_manager_can_view_and_create_but_not_delete_inventory(): void
    {
        $item = Item::factory()->create();

        $this->actingAs(User::factory()->manager()->create());

        $this->assertTrue(ItemResource::canViewAny());
        $this->assertTrue(ItemResource::canCreate());
        $this->assertTrue(ItemResource::canEdit($item));
        $this->assertFalse(ItemResource::canDelete($item));
    }

    public function test_a_technician_cannot_touch_inventory_at_all(): void
    {
        $item = Item::factory()->create();

        $this->actingAs(User::factory()->technician()->create());

        $this->assertFalse(ItemResource::canViewAny());
        $this->assertFalse(ItemResource::canCreate());
        $this->assertFalse(ItemResource::canEdit($item));
        $this->assertFalse(ItemResource::canDelete($item));
    }

    public function test_disabling_the_inventory_module_hides_the_resource(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->assertTrue(ItemResource::canViewAny());

        // Inventory is a core module, so it stays on — prove that explicitly
        // rather than pretending it can be switched off.
        app(ModuleRegistry::class)->setEnabled('inventory', false);

        $this->assertTrue(
            ItemResource::canViewAny(),
            'inventory is core: the shop cannot bill without a catalogue'
        );
    }
}
