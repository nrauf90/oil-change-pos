<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickAddItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    /*
    |--------------------------------------------------------------------------
    | index — the sale screen's item picker
    |--------------------------------------------------------------------------
    */

    public function test_index_returns_active_items_in_a_data_envelope(): void
    {
        Item::factory()->create(['name' => 'ZIC 10W-40 Synthetic', 'unit_cost' => 4500]);

        $this->getJson(route('quick-items.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'name', 'type', 'type_label', 'unit_cost', 'stock_level', 'low_stock_alert', 'is_low_on_stock'],
                ],
            ])
            ->assertJsonPath('data.0.name', 'ZIC 10W-40 Synthetic')
            ->assertJsonPath('data.0.type', 'product')
            ->assertJsonPath('data.0.type_label', 'Product')
            ->assertJsonPath('data.0.unit_cost', '4500.00');
    }

    public function test_index_excludes_inactive_items(): void
    {
        Item::factory()->create(['name' => 'Active Oil']);
        Item::factory()->inactive()->create(['name' => 'Discontinued Oil']);

        $this->getJson(route('quick-items.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Oil');
    }

    public function test_index_can_be_filtered_by_type(): void
    {
        Item::factory()->create(['name' => 'Engine Oil 5W-30']);
        Item::factory()->repair()->create(['name' => 'Suspension Tuning']);

        $this->getJson(route('quick-items.index', ['type' => 'repair']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Suspension Tuning')
            ->assertJsonPath('data.0.type', 'repair')
            ->assertJsonPath('data.0.type_label', 'Repair / Service');
    }

    public function test_index_can_be_searched_by_name(): void
    {
        Item::factory()->create(['name' => 'ZIC 10W-40']);
        Item::factory()->create(['name' => 'Shell Helix 5W-30']);

        $this->getJson(route('quick-items.index', ['q' => 'ZIC']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'ZIC 10W-40');
    }

    public function test_index_orders_items_by_name(): void
    {
        Item::factory()->create(['name' => 'Coolant']);
        Item::factory()->create(['name' => 'Air Filter']);
        Item::factory()->create(['name' => 'Brake Fluid']);

        $this->getJson(route('quick-items.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Air Filter')
            ->assertJsonPath('data.1.name', 'Brake Fluid')
            ->assertJsonPath('data.2.name', 'Coolant');
    }

    public function test_index_caps_the_number_of_items_returned(): void
    {
        Item::factory()->count(60)->create();

        $this->getJson(route('quick-items.index'))
            ->assertOk()
            ->assertJsonCount(50, 'data');
    }

    public function test_index_returns_a_null_unit_cost_as_null(): void
    {
        Item::factory()->withoutUnitCost()->create(['name' => 'AC Gas Refill']);

        $this->getJson(route('quick-items.index'))
            ->assertOk()
            ->assertJsonPath('data.0.unit_cost', null);
    }

    /*
    |--------------------------------------------------------------------------
    | store — the "Quick Add New Item" modal
    |--------------------------------------------------------------------------
    */

    public function test_store_creates_a_product_and_returns_201(): void
    {
        $response = $this->postJson(route('quick-items.store'), [
            'name' => 'Cabin Filter',
            'type' => 'product',
            'unit_cost' => 1200.50,
        ])->assertCreated();

        $item = Item::firstWhere('name', 'Cabin Filter');

        $response->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.name', 'Cabin Filter')
            ->assertJsonPath('data.type', 'product')
            ->assertJsonPath('data.type_label', 'Product')
            ->assertJsonPath('data.unit_cost', '1200.50');
    }

    public function test_store_creates_a_repair_with_a_null_unit_cost(): void
    {
        $this->postJson(route('quick-items.store'), [
            'name' => 'AC Gas Refill',
            'type' => 'repair',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'repair')
            ->assertJsonPath('data.type_label', 'Repair / Service')
            ->assertJsonPath('data.unit_cost', null);

        $this->assertDatabaseHas('items', [
            'name' => 'AC Gas Refill',
            'type' => 'repair',
            'unit_cost' => null,
        ]);
    }

    public function test_store_persists_the_row_to_the_items_table(): void
    {
        $this->postJson(route('quick-items.store'), [
            'name' => 'Wiper Blade',
            'type' => 'product',
            'unit_cost' => 850,
        ])->assertCreated();

        $this->assertDatabaseCount('items', 1);
        $this->assertDatabaseHas('items', [
            'name' => 'Wiper Blade',
            'type' => 'product',
            'unit_cost' => '850.00',
            'is_active' => true,
        ]);
    }

    public function test_store_response_carries_everything_the_front_end_needs_to_select_the_item(): void
    {
        $this->postJson(route('quick-items.store'), [
            'name' => 'Gear Oil 80W-90',
            'type' => 'product',
            'unit_cost' => 2100,
        ])
            ->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'type', 'type_label', 'unit_cost', 'stock_level', 'low_stock_alert', 'is_low_on_stock'],
            ]);

        $id = Item::firstWhere('name', 'Gear Oil 80W-90')->id;

        // The new row must be reachable through the picker feed straight away, so
        // the front-end can trust the injected <option> matches the master list.
        $this->getJson(route('quick-items.index', ['q' => 'Gear Oil']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_store_returns_422_when_the_name_is_missing(): void
    {
        $this->postJson(route('quick-items.store'), ['name' => '', 'type' => 'product'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['name']]);

        $this->assertDatabaseCount('items', 0);
    }

    public function test_store_returns_422_when_the_type_is_not_product_or_repair(): void
    {
        $this->postJson(route('quick-items.store'), ['name' => 'Mystery', 'type' => 'labour'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_store_returns_422_when_the_name_is_too_long(): void
    {
        $this->postJson(route('quick-items.store'), [
            'name' => str_repeat('a', 151),
            'type' => 'product',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_store_returns_422_when_the_unit_cost_is_negative(): void
    {
        $this->postJson(route('quick-items.store'), [
            'name' => 'Negative Cost',
            'type' => 'product',
            'unit_cost' => -5,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_cost');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_store_rejects_a_duplicate_name_without_creating_a_second_row(): void
    {
        Item::factory()->create(['name' => 'Air Filter']);

        $this->postJson(route('quick-items.store'), ['name' => 'Air Filter', 'type' => 'product'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('items', 1);
    }

    public function test_store_does_not_redirect_on_validation_failure(): void
    {
        // The modal sits on top of a half-typed bill: a 302 would bounce the
        // salesperson off the page and throw the cart away, so XHR validation
        // failures have to come back as 422 JSON.
        $response = $this->postJson(route('quick-items.store'), ['type' => 'product']);

        $response->assertStatus(422);
        $this->assertFalse($response->isRedirect());
    }

    public function test_store_trims_surrounding_whitespace_from_the_name(): void
    {
        $this->postJson(route('quick-items.store'), [
            'name' => '   Brake Cleaner   ',
            'type' => 'product',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Brake Cleaner');

        $this->assertDatabaseHas('items', ['name' => 'Brake Cleaner']);
    }

    public function test_store_treats_a_whitespace_only_name_as_missing(): void
    {
        $this->postJson(route('quick-items.store'), ['name' => '   ', 'type' => 'product'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('items', 0);
    }

    public function test_store_coerces_an_empty_string_unit_cost_to_null(): void
    {
        $this->postJson(route('quick-items.store'), [
            'name' => 'Radiator Flush',
            'type' => 'repair',
            'unit_cost' => '',
        ])
            ->assertCreated()
            ->assertJsonPath('data.unit_cost', null);

        $this->assertDatabaseHas('items', [
            'name' => 'Radiator Flush',
            'unit_cost' => null,
        ]);
    }

    public function test_store_creates_the_item_as_active_so_it_shows_up_in_the_picker(): void
    {
        $this->postJson(route('quick-items.store'), [
            'name' => 'Fuel Injector Cleaning',
            'type' => 'repair',
        ])->assertCreated();

        $this->getJson(route('quick-items.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Fuel Injector Cleaning');
    }
}
