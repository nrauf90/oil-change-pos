<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UnitOfMeasure;
use App\Filament\Resources\Items\ItemResource;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Models\Item;
use App\Models\Role as SpatieRole;
use App\Models\User;
use Filament\Schemas\Components\Component;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The screens for bulk consumables: describing how an item is counted, reading
 * stock back in its own unit, and booking in a delivery.
 *
 * The arithmetic itself lives in Item and is covered by MeasuredStockTest —
 * what is proved here is that the owner can reach it from a tablet.
 */
class MeasuredInventoryUiTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $overrides */
    private function oil(array $overrides = []): Item
    {
        return Item::factory()->create(array_merge([
            'name' => 'ZIC X7 10W-40',
            'unit_of_measure' => UnitOfMeasure::Litre,
            'pack_label' => 'Carton',
            'units_per_pack' => 4,
            'measure_per_unit' => 4,
            'stock_level' => 0,
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function gas(array $overrides = []): Item
    {
        return Item::factory()->repair()->create(array_merge([
            'name' => 'AC Gas Refill',
            'unit_of_measure' => UnitOfMeasure::Kilogram,
            'pack_label' => 'Cylinder',
            'units_per_pack' => 1,
            'measure_per_unit' => 13,
            'stock_level' => 13,
        ], $overrides));
    }

    /* ---------------------------------------------------------------- */
    /* Describing how an item is counted — the Blade item form */
    /* ---------------------------------------------------------------- */

    public function test_an_admin_can_create_an_oil_item_measured_in_litres_with_carton_packaging(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('items.store'), [
            'name' => 'ZIC X7 10W-40',
            'type' => 'product',
            'unit_of_measure' => 'litre',
            'pack_label' => 'Carton',
            'units_per_pack' => 4,
            'measure_per_unit' => 4,
            'stock_level' => 32,
            'low_stock_alert' => 8,
        ])->assertSessionHasNoErrors()->assertRedirect(route('items.index'));

        $item = Item::sole();

        $this->assertSame(UnitOfMeasure::Litre, $item->unit_of_measure);
        $this->assertSame('Carton', $item->pack_label);
        $this->assertSame(4, $item->units_per_pack);
        $this->assertSame('4.000', $item->measure_per_unit);
        $this->assertSame('16.000', $item->packContains());
    }

    public function test_the_item_form_offers_every_unit_of_measure(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->get(route('items.create'))->assertOk();

        foreach (UnitOfMeasure::cases() as $unit) {
            $response->assertSee('value="'.$unit->value.'"', false)->assertSee($unit->label());
        }
    }

    public function test_the_derived_pack_size_is_shown_back_to_the_owner(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $oil = $this->oil();

        $this->get(route('items.edit', $oil))->assertOk()->assertSee('16.000 L');
        $this->get(route('items.index'))->assertOk()->assertSee('16.000 L');
    }

    public function test_the_packaging_fields_are_hidden_for_a_piece_item(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $filter = Item::factory()->create(['name' => 'Oil Filter']);

        $this->get(route('items.edit', $filter))
            ->assertOk()
            ->assertSee('data-packaging="hidden"', false)
            ->assertDontSee('data-packaging="shown"', false);
    }

    public function test_the_packaging_fields_are_shown_for_a_measured_item(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('items.edit', $this->oil()))
            ->assertOk()
            ->assertSee('data-packaging="shown"', false)
            ->assertSee('name="units_per_pack"', false)
            ->assertSee('name="measure_per_unit"', false)
            ->assertSee('name="pack_label"', false);
    }

    /* ---------------------------------------------------------------- */
    /* Validation */
    /* ---------------------------------------------------------------- */

    public function test_a_fractional_stock_level_is_accepted(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // Stock used to be an integer column; half a litre of oil is a real amount.
        $this->post(route('items.store'), [
            'name' => 'Gear Oil',
            'type' => 'product',
            'unit_of_measure' => 'litre',
            'stock_level' => '2.5',
        ])->assertSessionHasNoErrors()->assertRedirect(route('items.index'));

        $this->assertSame('2.500', Item::sole()->stock_level);
    }

    public function test_an_unknown_unit_of_measure_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('items.store'), [
            'name' => 'Mystery Fluid',
            'type' => 'product',
            'unit_of_measure' => 'gallon',
        ])->assertSessionHasErrors('unit_of_measure');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_a_pack_cannot_hold_fewer_than_one_unit(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('items.store'), [
            'name' => 'Odd Oil',
            'type' => 'product',
            'unit_of_measure' => 'litre',
            'units_per_pack' => 0,
            'measure_per_unit' => -1,
        ])->assertSessionHasErrors(['units_per_pack', 'measure_per_unit']);
    }

    public function test_a_measured_repair_may_carry_stock(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // An AC gas refill is labour that empties a cylinder — the shop has to
        // know how much gas is left even though nothing sits on a shelf.
        $this->post(route('items.store'), [
            'name' => 'AC Gas Refill',
            'type' => 'repair',
            'unit_of_measure' => 'kilogram',
            'pack_label' => 'Cylinder',
            'units_per_pack' => 1,
            'measure_per_unit' => 13,
            'stock_level' => 13,
            'low_stock_alert' => 2,
        ])->assertSessionHasNoErrors()->assertRedirect(route('items.index'));

        $this->assertSame('13.000', Item::sole()->stock_level);
    }

    public function test_a_piece_repair_still_cannot_carry_stock(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('items.store'), [
            'name' => 'Wheel Alignment',
            'type' => 'repair',
            'unit_of_measure' => 'piece',
            'stock_level' => 10,
        ])->assertSessionHasErrors('stock_level');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_packaging_details_are_dropped_when_an_item_is_counted_in_pieces(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $oil = $this->oil();

        $this->put(route('items.update', $oil), [
            'name' => $oil->name,
            'type' => 'product',
            'unit_of_measure' => 'piece',
            'pack_label' => 'Carton',
            'units_per_pack' => 4,
            'measure_per_unit' => 4,
        ])->assertSessionHasNoErrors();

        $oil->refresh();

        $this->assertNull($oil->pack_label);
        $this->assertNull($oil->units_per_pack);
        $this->assertNull($oil->measure_per_unit);
    }

    /* ---------------------------------------------------------------- */
    /* The same choices in the back office */
    /* ---------------------------------------------------------------- */

    public function test_an_admin_can_describe_oil_packaging_from_the_admin_panel(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateItem::class)
            ->fillForm([
                'name' => 'ZIC X7 10W-40',
                'type' => 'product',
                'unit_of_measure' => UnitOfMeasure::Litre->value,
                'pack_label' => 'Carton',
                'units_per_pack' => 4,
                'measure_per_unit' => 4,
                'stock_level' => 2.5,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = Item::sole();

        $this->assertSame('16.000', $item->packContains());
        $this->assertSame('2.500', $item->stock_level);
    }

    public function test_an_admin_can_give_a_measured_repair_stock_from_the_admin_panel(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateItem::class)
            ->fillForm([
                'name' => 'AC Gas Refill',
                'type' => 'repair',
                'unit_of_measure' => UnitOfMeasure::Kilogram->value,
                'pack_label' => 'Cylinder',
                'units_per_pack' => 1,
                'measure_per_unit' => 13,
                'stock_level' => 13,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('13.000', Item::sole()->stock_level);
    }

    public function test_the_admin_form_hides_packaging_until_a_measured_unit_is_chosen(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateItem::class)
            ->fillForm(['unit_of_measure' => UnitOfMeasure::Piece->value])
            ->assertSchemaComponentExists(
                'packaging',
                checkComponentUsing: fn (Component $section): bool => $section->isHidden(),
            )
            ->fillForm(['unit_of_measure' => UnitOfMeasure::Litre->value])
            ->assertSchemaComponentExists(
                'packaging',
                checkComponentUsing: fn (Component $section): bool => $section->isVisible(),
            );
    }

    /* ---------------------------------------------------------------- */
    /* Stock read back in its own unit */
    /* ---------------------------------------------------------------- */

    public function test_the_inventory_list_shows_stock_in_the_items_own_unit(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->oil(['stock_level' => 32]);
        $this->gas();
        Item::factory()->create(['name' => 'Oil Filter', 'stock_level' => 7]);

        $this->get(route('items.index'))
            ->assertOk()
            ->assertSee('32.000 L')
            ->assertSee('13.000 kg');
    }

    public function test_the_admin_table_shows_stock_in_the_items_own_unit(): void
    {
        $this->oil(['stock_level' => 32]);
        $this->gas();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->assertSee('32.000 L')
            ->assertSee('13.000 kg');
    }

    /* ---------------------------------------------------------------- */
    /* Receiving a delivery */
    /* ---------------------------------------------------------------- */

    public function test_receiving_two_cartons_adds_thirty_two_litres_and_reports_the_new_balance(): void
    {
        $oil = $this->oil(['stock_level' => 0]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->callTableAction('receiveStock', $oil, ['packs' => 2])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertSame('32.000', $oil->refresh()->stock_level);
    }

    public function test_a_loose_measured_amount_can_be_received(): void
    {
        $oil = $this->oil(['stock_level' => 10]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->callTableAction('receiveStock', $oil, ['measure' => '2.5'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('12.500', $oil->refresh()->stock_level);
    }

    public function test_receiving_starts_tracking_an_item_that_was_never_counted(): void
    {
        $item = Item::factory()->create(['name' => 'Oil Filter', 'stock_level' => null]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->callTableAction('receiveStock', $item, ['measure' => '6'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('6.000', $item->refresh()->stock_level);
    }

    public function test_the_receive_modal_speaks_the_items_own_language(): void
    {
        $oil = $this->oil();

        $page = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->mountTableAction('receiveStock', $oil)
            ->assertSchemaComponentExists(
                'packs',
                checkComponentUsing: fn (Component $field): bool => $field->getLabel() === 'How many Cartons?',
            )
            ->assertSchemaComponentExists(
                'measure',
                checkComponentUsing: fn (Component $field): bool => $field->getLabel() === 'Loose amount (L)',
            );

        $this->assertSame('One Carton = 16.000 L', $page->instance()->getMountedAction()->getModalDescription());
    }

    public function test_an_item_without_packaging_is_only_asked_for_a_loose_amount(): void
    {
        $filter = Item::factory()->create(['name' => 'Oil Filter', 'stock_level' => 4]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->mountTableAction('receiveStock', $filter)
            ->assertSchemaComponentDoesNotExist('packs')
            ->assertSchemaComponentExists('measure');
    }

    public function test_a_negative_amount_is_refused(): void
    {
        $oil = $this->oil(['stock_level' => 10]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->callTableAction('receiveStock', $oil, ['measure' => '-5'])
            ->assertHasTableActionErrors(['measure']);

        $this->assertSame('10.000', $oil->refresh()->stock_level);
    }

    public function test_a_negative_pack_count_is_refused(): void
    {
        $oil = $this->oil(['stock_level' => 10]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->callTableAction('receiveStock', $oil, ['packs' => '-2'])
            ->assertHasTableActionErrors(['packs']);

        $this->assertSame('10.000', $oil->refresh()->stock_level);
    }

    public function test_receiving_nothing_at_all_is_refused(): void
    {
        $oil = $this->oil(['stock_level' => 10]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->callTableAction('receiveStock', $oil, [])
            ->assertHasTableActionErrors(['measure']);

        $this->assertSame('10.000', $oil->refresh()->stock_level);
    }

    /* ---------------------------------------------------------------- */
    /* Who may book in a delivery */
    /* ---------------------------------------------------------------- */

    public function test_a_manager_can_receive_stock(): void
    {
        $oil = $this->oil(['stock_level' => 0]);

        Livewire::actingAs(User::factory()->manager()->create())
            ->test(ListItems::class)
            ->callTableAction('receiveStock', $oil, ['packs' => 1])
            ->assertHasNoTableActionErrors();

        $this->assertSame('16.000', $oil->refresh()->stock_level);
    }

    public function test_a_technician_cannot_invoke_the_receive_stock_action(): void
    {
        $oil = $this->oil(['stock_level' => 10]);
        $technician = User::factory()->technician()->create();

        $this->actingAs($technician);
        $this->assertFalse(ItemResource::canManageStock(), 'a technician holds no stock permission');

        // The action is an HTTP endpoint on the inventory page, and that page is
        // shut to a technician: mounting the component 403s before any action
        // can be reached.
        Livewire::actingAs($technician)
            ->test(ListItems::class)
            ->assertForbidden();

        $this->assertSame('10.000', $oil->refresh()->stock_level);
    }

    public function test_the_receive_stock_action_is_gated_on_the_stock_permission_not_just_the_page(): void
    {
        $oil = $this->oil(['stock_level' => 10]);
        $manager = User::factory()->manager()->create();

        // Strip the one permission while leaving the manager on the inventory
        // screen, so what is proved is the action's own gate.
        SpatieRole::findByName(Role::Manager->value)->revokePermissionTo(Permission::ManageStock->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Livewire::actingAs($manager)
            ->test(ListItems::class)
            ->assertSuccessful()
            ->assertTableActionHidden('receiveStock', $oil);

        $this->assertSame('10.000', $oil->refresh()->stock_level);
    }

    public function test_the_receive_stock_permission_is_held_by_admins_and_managers_only(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(ItemResource::canManageStock());

        $this->actingAs(User::factory()->manager()->create());
        $this->assertTrue(ItemResource::canManageStock());

        $this->actingAs(User::factory()->technician()->create());
        $this->assertFalse(ItemResource::canManageStock());
    }
}
