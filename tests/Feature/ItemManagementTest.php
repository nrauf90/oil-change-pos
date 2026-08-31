<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_inventory_index_lists_existing_items(): void
    {
        Item::factory()->create(['name' => 'ZIC 10W-40 Synthetic']);
        Item::factory()->repair()->create(['name' => 'Brake Pad Replacement']);

        $this->get(route('items.index'))
            ->assertOk()
            ->assertSee('ZIC 10W-40 Synthetic')
            ->assertSee('Brake Pad Replacement');
    }

    public function test_a_product_item_can_be_created(): void
    {
        $this->post(route('items.store'), [
            'name' => 'Cabin Filter',
            'type' => 'product',
            'unit_cost' => 1200.50,
        ])->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'name' => 'Cabin Filter',
            'type' => 'product',
            'unit_cost' => '1200.50',
        ]);
    }

    public function test_a_repair_item_can_be_created_without_a_unit_cost(): void
    {
        $this->post(route('items.store'), [
            'name' => 'AC Gas Refill',
            'type' => 'repair',
        ])->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'name' => 'AC Gas Refill',
            'type' => 'repair',
            'unit_cost' => null,
        ]);
    }

    public function test_item_name_is_required(): void
    {
        $this->post(route('items.store'), ['name' => '', 'type' => 'product'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_item_type_must_be_product_or_repair(): void
    {
        $this->post(route('items.store'), ['name' => 'Mystery', 'type' => 'labour'])
            ->assertSessionHasErrors('type');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_duplicate_item_names_are_rejected(): void
    {
        Item::factory()->create(['name' => 'Air Filter']);

        $this->post(route('items.store'), ['name' => 'Air Filter', 'type' => 'product'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('items', 1);
    }

    public function test_an_item_can_be_updated(): void
    {
        $item = Item::factory()->create(['name' => 'Old Name', 'unit_cost' => 100]);

        $this->put(route('items.update', $item), [
            'name' => 'New Name',
            'type' => 'repair',
            'unit_cost' => 250,
        ])->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'name' => 'New Name',
            'type' => 'repair',
            'unit_cost' => '250.00',
        ]);
    }

    public function test_an_item_can_be_deleted(): void
    {
        $item = Item::factory()->create();

        $this->delete(route('items.destroy', $item))
            ->assertRedirect(route('items.index'));

        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }

    public function test_inventory_can_be_filtered_by_type(): void
    {
        Item::factory()->create(['name' => 'Engine Oil 5W-30']);
        Item::factory()->repair()->create(['name' => 'Suspension Tuning']);

        $this->get(route('items.index', ['type' => 'repair']))
            ->assertOk()
            ->assertSee('Suspension Tuning')
            ->assertDontSee('Engine Oil 5W-30');
    }

    public function test_inventory_can_be_searched_by_name(): void
    {
        Item::factory()->create(['name' => 'ZIC 10W-40']);
        Item::factory()->create(['name' => 'Shell Helix 5W-30']);

        $this->get(route('items.index', ['q' => 'ZIC']))
            ->assertOk()
            ->assertSee('ZIC 10W-40')
            ->assertDontSee('Shell Helix 5W-30');
    }

    /*
    |--------------------------------------------------------------------------
    | Hardening — findings from the security review
    |--------------------------------------------------------------------------
    */

    public function test_a_bare_wildcard_search_does_not_return_the_whole_catalogue(): void
    {
        Item::factory()->create(['name' => 'ZIC 10W-40']);
        Item::factory()->create(['name' => 'Shell Helix 5W-30']);

        $this->get(route('items.index', ['q' => '%']))
            ->assertOk()
            ->assertDontSee('ZIC 10W-40')
            ->assertDontSee('Shell Helix 5W-30');
    }

    public function test_an_underscore_search_is_treated_as_a_literal(): void
    {
        Item::factory()->create(['name' => 'ZIC 10W-40']);

        $this->assertSame(0, Item::search('_')->count());
    }

    public function test_an_item_name_containing_a_percent_sign_is_still_findable(): void
    {
        $discounted = Item::factory()->create(['name' => '50% Synthetic Blend']);
        Item::factory()->create(['name' => 'Plain Mineral Oil']);

        $this->assertSame([$discounted->id], Item::search('50%')->pluck('id')->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Active / inactive items
    |--------------------------------------------------------------------------
    */

    public function test_an_item_can_be_deactivated_from_the_item_form(): void
    {
        $item = Item::factory()->create(['name' => 'Discontinued Oil']);

        $this->put(route('items.update', $item), [
            'name' => 'Discontinued Oil',
            'type' => 'product',
            'is_active' => '0',
        ])->assertRedirect(route('items.index'));

        $this->assertFalse($item->refresh()->is_active);
    }

    public function test_an_item_is_created_active_by_default(): void
    {
        $this->post(route('items.store'), ['name' => 'Fresh Oil', 'type' => 'product'])
            ->assertRedirect(route('items.index'));

        $this->assertTrue(Item::sole()->is_active);
    }

    public function test_the_inventory_list_flags_inactive_items(): void
    {
        Item::factory()->inactive()->create(['name' => 'Discontinued Oil']);

        $this->get(route('items.index'))
            ->assertOk()
            ->assertSee('Discontinued Oil')
            ->assertSee('data-status-badge="inactive"', false);
    }

    public function test_the_inventory_list_does_not_label_a_fully_active_catalogue(): void
    {
        Item::factory()->create(['name' => 'Fresh Oil']);

        $this->get(route('items.index'))->assertOk()->assertDontSee('data-status-badge="inactive"', false);
    }

    public function test_the_inventory_list_can_be_filtered_to_inactive_items_only(): void
    {
        Item::factory()->create(['name' => 'Fresh Oil']);
        Item::factory()->inactive()->create(['name' => 'Discontinued Oil']);

        $this->get(route('items.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Discontinued Oil')
            ->assertDontSee('Fresh Oil');
    }

    public function test_the_inventory_list_can_be_filtered_to_active_items_only(): void
    {
        Item::factory()->create(['name' => 'Fresh Oil']);
        Item::factory()->inactive()->create(['name' => 'Discontinued Oil']);

        $this->get(route('items.index', ['status' => 'active']))
            ->assertOk()
            ->assertSee('Fresh Oil')
            ->assertDontSee('Discontinued Oil');
    }
}
