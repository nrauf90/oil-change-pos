<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRD §1 gives the Admin/Owner alone the right to "modify master inventory
 * reference costs" and to assess margins. A Manager runs the counter and must
 * never see, nor rewrite, what the shop paid for a part.
 *
 * Filament enforced this; the Blade and JSON paths did not. These tests cover
 * every route a Manager can actually reach.
 */
class UnitCostConfidentialityTest extends TestCase
{
    use RefreshDatabase;

    private function item(): Item
    {
        return Item::factory()->create([
            'name' => 'ZIC X7 10W-40',
            'unit_cost' => 6800,
            'stock_level' => 10,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* Reading */
    /* ---------------------------------------------------------------- */

    public function test_the_inventory_list_hides_unit_cost_from_a_manager(): void
    {
        $this->item();

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('items.index'))
            ->assertOk()
            ->assertSee('ZIC X7 10W-40')
            ->assertDontSee('6,800.00')
            ->assertDontSee('6800.00');
    }

    /**
     * Unit cost was dropped from the inventory list entirely — for every
     * viewer, admin included — in favour of the selling price column. It
     * still shows on the item form, gated as before; see
     * `test_the_item_form_shows_the_unit_cost_field_to_an_admin` below.
     */
    public function test_the_inventory_list_hides_unit_cost_from_an_admin_too(): void
    {
        $this->item();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('items.index'))
            ->assertOk()
            ->assertDontSee('6,800.00')
            ->assertDontSee('6800.00');
    }

    public function test_the_item_form_hides_the_unit_cost_field_from_a_manager(): void
    {
        $item = $this->item();

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('items.edit', $item))
            ->assertOk()
            ->assertDontSee('6800.00')
            ->assertDontSee('name="unit_cost"', false);
    }

    public function test_the_item_form_shows_the_unit_cost_field_to_an_admin(): void
    {
        $item = $this->item();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('items.edit', $item))
            ->assertOk()
            ->assertSee('name="unit_cost"', false);
    }

    public function test_the_sale_screen_never_ships_unit_cost_to_a_manager(): void
    {
        $this->item();

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('pos.create'))
            ->assertOk()
            ->assertSee('ZIC X7 10W-40')
            ->assertDontSee('6800')
            ->assertDontSee('6,800.00');
    }

    public function test_the_sale_screen_may_hint_the_unit_cost_to_an_admin(): void
    {
        $this->item();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('pos.create'))
            ->assertOk()
            ->assertSee('6800');
    }

    public function test_the_quick_item_json_omits_unit_cost_for_a_manager(): void
    {
        $this->item();

        $response = $this->actingAs(User::factory()->manager()->create())
            ->getJson(route('quick-items.index'));

        $response->assertOk();
        $this->assertArrayNotHasKey('unit_cost', $response->json('data.0'));
    }

    public function test_the_quick_item_json_includes_unit_cost_for_an_admin(): void
    {
        $this->item();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('quick-items.index'))
            ->assertOk()
            ->assertJsonPath('data.0.unit_cost', '6800.00');
    }

    /* ---------------------------------------------------------------- */
    /* Writing — the more serious half */
    /* ---------------------------------------------------------------- */

    public function test_a_manager_cannot_rewrite_the_unit_cost_of_an_existing_item(): void
    {
        $item = $this->item();

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('items.update', $item), [
                'name' => 'ZIC X7 10W-40',
                'type' => 'product',
                'unit_cost' => '1',
                'stock_level' => 10,
            ]);

        $this->assertSame('6800.00', $item->refresh()->unit_cost, 'a manager rewrote the owner costing');
    }

    /**
     * Regression: every other test here sends `unit_cost` in the body.
     * `FormRequest::validationData()` returns `all()`, which merges the query
     * string over the body bag, so a strip that only clears the request and
     * JSON bags leaves `?unit_cost=` intact all the way into `validated()`.
     */
    public function test_a_manager_cannot_rewrite_the_unit_cost_through_the_query_string(): void
    {
        $item = $this->item();

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('items.update', $item).'?unit_cost=1', [
                'name' => 'ZIC X7 10W-40',
                'type' => 'product',
                'stock_level' => 10,
            ]);

        $this->assertSame('6800.00', $item->refresh()->unit_cost, 'a manager rewrote the owner costing via the query string');
    }

    public function test_a_manager_cannot_set_a_unit_cost_through_the_query_string_when_creating(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('items.store').'?unit_cost=999', [
                'name' => 'Sneaky Query Filter',
                'type' => 'product',
            ]);

        $this->assertNull(Item::where('name', 'Sneaky Query Filter')->sole()->unit_cost);
    }

    public function test_a_manager_cannot_set_a_unit_cost_through_quick_add_query_string(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->postJson(route('quick-items.store').'?unit_cost=999', [
                'name' => 'Sneaky Quick Query Filter',
                'type' => 'product',
            ]);

        $this->assertNull(Item::where('name', 'Sneaky Quick Query Filter')->sole()->unit_cost);
    }

    public function test_an_admin_can_still_set_the_unit_cost(): void
    {
        $item = $this->item();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('items.update', $item), [
                'name' => 'ZIC X7 10W-40',
                'type' => 'product',
                'unit_cost' => '7200',
                'stock_level' => 10,
            ]);

        $this->assertSame('7200.00', $item->refresh()->unit_cost);
    }

    public function test_a_manager_may_still_edit_everything_else_about_an_item(): void
    {
        $item = $this->item();

        $this->actingAs(User::factory()->manager()->create())
            ->put(route('items.update', $item), [
                'name' => 'ZIC X7 10W-40 (New Pack)',
                'type' => 'product',
                'stock_level' => 42,
            ])->assertRedirect(route('items.index'));

        $item->refresh();

        $this->assertSame('ZIC X7 10W-40 (New Pack)', $item->name);
        $this->assertSame('42.000', $item->stock_level);
        $this->assertSame('6800.00', $item->unit_cost);
    }

    public function test_a_manager_cannot_set_a_unit_cost_when_creating_an_item(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('items.store'), [
                'name' => 'Sneaky Filter',
                'type' => 'product',
                'unit_cost' => '999',
            ]);

        $this->assertNull(Item::where('name', 'Sneaky Filter')->sole()->unit_cost);
    }

    public function test_a_manager_cannot_set_a_unit_cost_through_quick_add(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->postJson(route('quick-items.store'), [
                'name' => 'Sneaky Quick Filter',
                'type' => 'product',
                'unit_cost' => '999',
            ])->assertCreated();

        $this->assertNull(Item::where('name', 'Sneaky Quick Filter')->sole()->unit_cost);
    }

    public function test_an_admin_can_still_set_a_unit_cost(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('items.store'), [
                'name' => 'Properly Costed Filter',
                'type' => 'product',
                'unit_cost' => '1250.50',
            ])->assertRedirect(route('items.index'));

        $this->assertSame('1250.50', Item::where('name', 'Properly Costed Filter')->sole()->unit_cost);
    }

    public function test_an_admin_can_still_set_a_unit_cost_through_quick_add(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('quick-items.store'), [
                'name' => 'Quick Costed Filter',
                'type' => 'product',
                'unit_cost' => '333.25',
            ])->assertCreated();

        $this->assertSame('333.25', Item::where('name', 'Quick Costed Filter')->sole()->unit_cost);
    }

    public function test_a_technician_cannot_reach_inventory_at_all(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->get(route('items.index'))
            ->assertForbidden();
    }
}
