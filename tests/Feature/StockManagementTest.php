<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Models\Item;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    /*
    |--------------------------------------------------------------------------
    | Schema — stock is optional, and only Products may carry it
    |--------------------------------------------------------------------------
    */

    public function test_an_item_is_not_stock_tracked_by_default(): void
    {
        $item = Item::factory()->create();

        $this->assertNull($item->stock_level, 'null stock means "not tracked"');
        $this->assertNull($item->low_stock_alert);
    }

    public function test_a_product_can_be_created_with_a_stock_level_and_alert_threshold(): void
    {
        $this->post(route('items.store'), [
            'name' => 'ZIC X7 10W-40',
            'type' => 'product',
            'unit_cost' => 4500,
            'stock_level' => 24,
            'low_stock_alert' => 5,
        ])->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'name' => 'ZIC X7 10W-40',
            'stock_level' => 24,
            'low_stock_alert' => 5,
        ], 'tenant');
    }

    public function test_a_product_may_be_left_untracked(): void
    {
        $this->post(route('items.store'), [
            'name' => 'Assorted Washers',
            'type' => 'product',
            'stock_level' => '',
            'low_stock_alert' => '',
        ])->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'name' => 'Assorted Washers',
            'stock_level' => null,
            'low_stock_alert' => null,
        ], 'tenant');
    }

    public function test_a_repair_may_not_carry_a_stock_level(): void
    {
        $this->post(route('items.store'), [
            'name' => 'AC Gas Refill',
            'type' => 'repair',
            'stock_level' => 10,
        ])->assertSessionHasErrors('stock_level');

        $this->assertDatabaseCount('items', 0, 'tenant');
    }

    public function test_a_repair_may_not_carry_a_low_stock_alert(): void
    {
        $this->post(route('items.store'), [
            'name' => 'Wheel Alignment',
            'type' => 'repair',
            'low_stock_alert' => 3,
        ])->assertSessionHasErrors('low_stock_alert');

        $this->assertDatabaseCount('items', 0, 'tenant');
    }

    public function test_a_repair_saves_normally_when_no_stock_is_sent(): void
    {
        $this->post(route('items.store'), [
            'name' => 'Radiator Flush',
            'type' => 'repair',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('items', ['name' => 'Radiator Flush', 'stock_level' => null], 'tenant');
    }

    public function test_a_negative_stock_level_is_rejected_on_the_item_form(): void
    {
        $this->post(route('items.store'), [
            'name' => 'Impossible Oil',
            'type' => 'product',
            'stock_level' => -3,
        ])->assertSessionHasErrors('stock_level');
    }

    public function test_the_quick_add_endpoint_also_refuses_stock_on_a_repair(): void
    {
        $this->postJson(route('quick-items.store'), [
            'name' => 'Brake Bleeding',
            'type' => 'repair',
            'stock_level' => 4,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock_level');

        $this->assertDatabaseCount('items', 0, 'tenant');
    }

    public function test_the_quick_add_endpoint_accepts_an_opening_stock_for_a_product(): void
    {
        $this->postJson(route('quick-items.store'), [
            'name' => 'Cabin Filter',
            'type' => 'product',
            'unit_cost' => 1200,
            'stock_level' => 12,
            'low_stock_alert' => 2,
        ])
            ->assertCreated()
            ->assertJsonPath('data.stock_level', '12.000')
            ->assertJsonPath('data.low_stock_alert', '2.000');
    }

    /*
    |--------------------------------------------------------------------------
    | Low-stock reporting
    |--------------------------------------------------------------------------
    */

    public function test_an_item_is_low_on_stock_when_it_sits_at_or_below_its_threshold(): void
    {
        $this->assertTrue(Item::factory()->tracked(2, 5)->create()->isLowOnStock());
        $this->assertTrue(Item::factory()->tracked(5, 5)->create()->isLowOnStock());
        $this->assertFalse(Item::factory()->tracked(6, 5)->create()->isLowOnStock());
    }

    public function test_an_item_without_a_threshold_is_never_reported_low(): void
    {
        $this->assertFalse(Item::factory()->tracked(0, null)->create()->isLowOnStock());
    }

    public function test_an_untracked_item_is_never_reported_low(): void
    {
        $this->assertFalse(Item::factory()->create(['stock_level' => null, 'low_stock_alert' => 5])->isLowOnStock());
    }

    public function test_the_low_stock_scope_returns_only_items_at_or_below_their_threshold(): void
    {
        $low = Item::factory()->tracked(1, 5)->create(['name' => 'Nearly Out']);
        Item::factory()->tracked(50, 5)->create(['name' => 'Plenty Left']);
        Item::factory()->create(['name' => 'Not Tracked']);

        $this->assertSame([$low->id], Item::lowStock()->pluck('id')->all());
    }

    public function test_the_inventory_screen_shows_stock_and_flags_low_items(): void
    {
        Item::factory()->tracked(1, 5)->create(['name' => 'Nearly Out']);
        Item::factory()->tracked(50, 5)->create(['name' => 'Plenty Left']);

        $response = $this->get(route('items.index'))->assertOk();

        $response->assertSee('Nearly Out')
            ->assertSee('Plenty Left')
            ->assertSee('Low stock');
    }

    public function test_the_inventory_screen_does_not_flag_a_healthy_catalogue(): void
    {
        Item::factory()->tracked(50, 5)->create(['name' => 'Plenty Left']);

        $this->get(route('items.index'))->assertOk()->assertDontSee('Low stock');
    }
    /*
    |--------------------------------------------------------------------------
    | Stock deduction on checkout (PRD §2)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function checkout(array $lines): array
    {
        return [
            'customer_name' => 'Ali Raza',
            'labor_charge' => '',
            'misc_charge' => '',
            'lines' => $lines,
        ];
    }

    public function test_selling_a_stock_tracked_product_deducts_one_unit(): void
    {
        $item = Item::factory()->tracked(10)->create();

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $item->id, 'item_name' => $item->name, 'type' => 'product', 'manually_charged_price' => '4200'],
        ]))->assertRedirect();

        $this->assertSame('9.000', $item->refresh()->stock_level);
    }

    public function test_selling_several_units_deducts_the_line_quantity(): void
    {
        $item = Item::factory()->tracked(10)->create();

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $item->id, 'item_name' => $item->name, 'type' => 'product', 'quantity' => 4, 'manually_charged_price' => '4200'],
        ]))->assertRedirect();

        $this->assertSame('6.000', $item->refresh()->stock_level);
    }

    public function test_two_lines_of_the_same_product_deduct_the_combined_quantity(): void
    {
        $item = Item::factory()->tracked(10)->create();

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $item->id, 'item_name' => $item->name, 'type' => 'product', 'quantity' => 2, 'manually_charged_price' => '1000'],
            ['item_id' => $item->id, 'item_name' => $item->name, 'type' => 'product', 'quantity' => 3, 'manually_charged_price' => '1500'],
        ]))->assertRedirect();

        $this->assertSame('5.000', $item->refresh()->stock_level);
    }

    public function test_an_untracked_product_deducts_nothing_and_stays_untracked(): void
    {
        $item = Item::factory()->create(['stock_level' => null]);

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $item->id, 'item_name' => $item->name, 'type' => 'product', 'quantity' => 3, 'manually_charged_price' => '4200'],
        ]))->assertRedirect();

        $this->assertNull($item->refresh()->stock_level);
    }

    public function test_a_repair_line_deducts_no_stock(): void
    {
        $repair = Item::factory()->repair()->create();

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $repair->id, 'item_name' => $repair->name, 'type' => 'repair', 'quantity' => 2, 'manually_charged_price' => '3500'],
        ]))->assertRedirect();

        $this->assertNull($repair->refresh()->stock_level);
    }

    public function test_a_repair_that_somehow_holds_a_stock_number_still_deducts_nothing(): void
    {
        // The item form refuses stock on a Repair, but an old row or a direct DB
        // edit could still carry one. Type, not the presence of a number, decides.
        $repair = Item::factory()->repair()->create(['stock_level' => 10, 'low_stock_alert' => 2]);

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $repair->id, 'item_name' => $repair->name, 'type' => 'repair', 'quantity' => 3, 'manually_charged_price' => '3500'],
        ]))->assertRedirect();

        $this->assertSame('10.000', $repair->refresh()->stock_level);
    }

    public function test_a_custom_line_deducts_no_stock_even_if_an_item_id_is_smuggled_in(): void
    {
        $item = Item::factory()->tracked(10)->create();

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $item->id, 'item_name' => 'Ad-hoc welding', 'type' => 'custom', 'quantity' => 5, 'manually_charged_price' => '750'],
        ]))->assertRedirect();

        $this->assertSame('10.000', $item->refresh()->stock_level);
    }

    public function test_a_rejected_checkout_deducts_nothing(): void
    {
        $item = Item::factory()->tracked(10)->create();

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $item->id, 'item_name' => $item->name, 'type' => 'product', 'quantity' => 2, 'manually_charged_price' => '1000'],
            ['item_id' => null, 'item_name' => '', 'type' => 'custom', 'manually_charged_price' => '200'],
        ]))->assertSessionHasErrors();

        $this->assertDatabaseCount('sales', 0, 'tenant');
        $this->assertSame('10.000', $item->refresh()->stock_level);
    }

    /**
     * Decision (the PRD is silent): overselling is ALLOWED and never blocks a sale.
     * The part is physically in the customer's hands; a drifted count must never
     * stop the shop billing a paying customer. The negative balance is the signal
     * that the count needs correcting.
     */
    public function test_stock_may_go_negative_rather_than_blocking_a_paying_customer(): void
    {
        $item = Item::factory()->tracked(1)->create();

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $item->id, 'item_name' => $item->name, 'type' => 'product', 'quantity' => 5, 'manually_charged_price' => '4200'],
        ]))->assertRedirect();

        $this->assertDatabaseCount('sales', 1, 'tenant');
        $this->assertSame('-4.000', $item->refresh()->stock_level);
    }

    public function test_the_deduction_is_a_relative_sql_decrement_so_concurrent_checkouts_cannot_clobber_each_other(): void
    {
        $item = Item::factory()->tracked(10)->create();

        $statements = [];
        \DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $item->id, 'item_name' => $item->name, 'type' => 'product', 'quantity' => 3, 'manually_charged_price' => '4200'],
        ]))->assertRedirect();

        $decrements = array_filter(
            $statements,
            fn (string $sql) => preg_match('/update .*items.* set .*stock_level.* = .*stock_level.* -/i', $sql) === 1
        );

        $this->assertNotEmpty(
            $decrements,
            'stock must be written with a relative decrement, not a read-modify-write'
        );
    }

    public function test_back_to_back_checkouts_accumulate_their_deductions(): void
    {
        $item = Item::factory()->tracked(10)->create();

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $item->id, 'item_name' => $item->name, 'type' => 'product', 'quantity' => 3, 'manually_charged_price' => '1000'],
        ]))->assertRedirect();

        $this->post(route('sales.store'), $this->checkout([
            ['item_id' => $item->id, 'item_name' => $item->name, 'type' => 'product', 'quantity' => 4, 'manually_charged_price' => '1000'],
        ]))->assertRedirect();

        $this->assertSame('3.000', $item->refresh()->stock_level);
    }

    /* ---------------------------------------------------------------- */
    /* Deleting a bill returns what it took */
    /* ---------------------------------------------------------------- */

    /**
     * Deleting an invoice means it never happened, so the units it drew go
     * back. Without this the shelf drifts downward with every mis-keyed bill
     * and the low-stock alert fires against goods still physically present.
     */
    public function test_deleting_a_sale_returns_piece_stock_to_the_shelf(): void
    {
        $filter = Item::factory()->create([
            'name' => 'Cabin Filter',
            'type' => ItemType::Product,
            'stock_level' => 10,
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('sales.store'), [
            'customer_name' => 'Ali Raza',
            'labor_charge' => '',
            'misc_charge' => '',
            'lines' => [[
                'item_id' => $filter->id,
                'item_name' => $filter->name,
                'type' => 'product',
                'quantity' => 3,
                'manually_charged_price' => '2400',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('7.000', $filter->refresh()->stock_level);

        $this->delete(route('sales.destroy', Sale::sole()))->assertRedirect();

        $this->assertSame('10.000', $filter->refresh()->stock_level, 'the deleted bill did not return its stock');
    }

    public function test_deleting_a_sale_returns_only_what_was_dispensed_for_a_measured_line(): void
    {
        $oil = Item::factory()->create([
            'name' => 'ZIC X7 10W-40',
            'type' => ItemType::Product,
            'unit_of_measure' => UnitOfMeasure::Litre,
            'units_per_pack' => 4,
            'measure_per_unit' => 4,
            'stock_level' => 20,
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('sales.store'), [
            'customer_name' => 'Ali Raza',
            'labor_charge' => '',
            'misc_charge' => '',
            'lines' => [[
                'item_id' => $oil->id,
                'item_name' => $oil->name,
                'type' => 'product',
                'quantity' => 1,
                'dispensed_quantity' => '3.5',
                'manually_charged_price' => '2600',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('16.500', $oil->refresh()->stock_level);

        $this->delete(route('sales.destroy', Sale::sole()))->assertRedirect();

        $this->assertSame('20.000', $oil->refresh()->stock_level);
    }

    /** A repair never drew stock, so deleting its bill must not invent any. */
    public function test_deleting_a_sale_with_a_repair_line_invents_no_stock(): void
    {
        $repair = Item::factory()->create([
            'name' => 'Oil change labour',
            'type' => ItemType::Repair,
            'stock_level' => 5,
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('sales.store'), [
            'customer_name' => 'Ali Raza',
            'labor_charge' => '',
            'misc_charge' => '',
            'lines' => [[
                'item_id' => $repair->id,
                'item_name' => $repair->name,
                'type' => 'repair',
                'quantity' => 1,
                'manually_charged_price' => '800',
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('5.000', $repair->refresh()->stock_level);

        $this->delete(route('sales.destroy', Sale::sole()))->assertRedirect();

        $this->assertSame('5.000', $repair->refresh()->stock_level);
    }
}
