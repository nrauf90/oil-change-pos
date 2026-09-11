<?php

namespace Tests\Feature;

use App\Enums\SaleLineType;
use App\Models\Item;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Ali Raza',
            'phone' => '03001234567',
            'vehicle_model' => 'Toyota Corolla 2018',
            'vehicle_plate' => 'ABC-123',
            'mileage' => 84500,
            'labor_charge' => '',
            'misc_charge' => '',
            'lines' => [
                ['item_id' => null, 'item_name' => 'Custom work', 'type' => 'custom', 'manually_charged_price' => '1000'],
            ],
        ], $overrides);
    }

    public function test_the_sale_screen_loads(): void
    {
        Item::factory()->create(['name' => 'ZIC 10W-40']);

        $this->get(route('pos.create'))->assertOk()->assertSee('ZIC 10W-40');
    }

    public function test_a_sale_is_recorded_with_customer_and_vehicle_details(): void
    {
        $this->post(route('sales.store'), $this->payload(['next_checkup_mileage' => 90000]))->assertRedirect();

        $this->assertDatabaseHas('sales', [
            'customer_name' => 'Ali Raza',
            'phone' => '03001234567',
            'vehicle_model' => 'Toyota Corolla 2018',
            'vehicle_plate' => 'ABC-123',
            'mileage' => 84500,
            'next_checkup_mileage' => 90000,
        ], 'tenant');
    }

    public function test_checkout_redirects_to_the_printable_invoice(): void
    {
        $this->post(route('sales.store'), $this->payload())
            ->assertRedirect(route('sales.show', Sale::latest('id')->first()));
    }

    public function test_the_total_is_the_sum_of_the_manually_typed_numbers(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'labor_charge' => '500',
            'misc_charge' => '250.50',
            'lines' => [
                ['item_id' => null, 'item_name' => 'Oil', 'type' => 'product', 'manually_charged_price' => '4200'],
                ['item_id' => null, 'item_name' => 'Oil filter', 'type' => 'product', 'manually_charged_price' => '850'],
                ['item_id' => null, 'item_name' => 'Brake pads', 'type' => 'repair', 'manually_charged_price' => '3000'],
            ],
        ]));

        $this->assertSame('8800.50', Sale::sole()->total_amount);
    }

    public function test_the_inventory_unit_cost_never_reaches_the_invoice(): void
    {
        $item = Item::factory()->create(['name' => 'ZIC 10W-40', 'unit_cost' => 9999]);

        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => $item->id, 'item_name' => 'ZIC 10W-40', 'type' => 'product', 'manually_charged_price' => '100'],
            ],
        ]));

        $sale = Sale::sole();

        $this->assertSame('100.00', $sale->total_amount);
        $this->assertSame('100.00', $sale->lines->first()->manually_charged_price);
    }

    public function test_a_client_supplied_total_is_ignored_and_recomputed_on_the_server(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'total_amount' => '1',
            'lines' => [
                ['item_id' => null, 'item_name' => 'Oil', 'type' => 'product', 'manually_charged_price' => '5000'],
            ],
        ]));

        $this->assertSame('5000.00', Sale::sole()->total_amount);
    }

    public function test_a_client_supplied_invoice_number_is_ignored(): void
    {
        $this->post(route('sales.store'), $this->payload(['invoice_number' => 'HACKED-0001']));

        $this->assertNotSame('HACKED-0001', Sale::sole()->invoice_number);
    }

    public function test_all_three_line_kinds_can_be_mixed_on_one_bill(): void
    {
        $oil = Item::factory()->create(['name' => 'ZIC 10W-40']);
        $repair = Item::factory()->repair()->create(['name' => 'Brake Pad Replacement']);

        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => $oil->id, 'item_name' => 'ZIC 10W-40', 'type' => 'product', 'manually_charged_price' => '4200'],
                ['item_id' => $repair->id, 'item_name' => 'Brake Pad Replacement', 'type' => 'repair', 'manually_charged_price' => '3500'],
                ['item_id' => null, 'item_name' => 'Fixed jammed door latch', 'type' => 'custom', 'manually_charged_price' => '1200'],
            ],
        ]));

        $sale = Sale::sole();

        $this->assertCount(3, $sale->lines);
        $this->assertSame(
            [SaleLineType::Product, SaleLineType::Repair, SaleLineType::Custom],
            $sale->lines->pluck('type')->all()
        );
        $this->assertSame('8900.00', $sale->total_amount);
    }

    public function test_the_line_name_is_snapshotted_from_inventory_not_trusted_from_the_client(): void
    {
        $item = Item::factory()->create(['name' => 'ZIC 10W-40']);

        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => $item->id, 'item_name' => 'Free Ferrari', 'type' => 'product', 'manually_charged_price' => '10'],
            ],
        ]));

        $this->assertSame('ZIC 10W-40', Sale::sole()->lines->first()->item_name);
    }

    public function test_a_custom_line_is_stored_without_an_inventory_link(): void
    {
        $item = Item::factory()->create();

        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => $item->id, 'item_name' => 'Ad-hoc welding', 'type' => 'custom', 'manually_charged_price' => '750'],
            ],
        ]));

        $line = Sale::sole()->lines->first();

        $this->assertNull($line->item_id, 'a custom line must not be attached to inventory');
        $this->assertSame('Ad-hoc welding', $line->item_name);
    }

    public function test_labor_and_misc_charges_alone_can_make_a_valid_sale(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [],
            'labor_charge' => '1500',
            'misc_charge' => '200',
        ]))->assertRedirect();

        $sale = Sale::sole();

        $this->assertSame('1700.00', $sale->total_amount);
        $this->assertSame('1500.00', $sale->labor_charge);
        $this->assertSame('200.00', $sale->misc_charge);
    }

    public function test_blank_labor_and_misc_charges_are_stored_as_zero(): void
    {
        $this->post(route('sales.store'), $this->payload());

        $sale = Sale::sole();

        $this->assertSame('0.00', $sale->labor_charge);
        $this->assertSame('0.00', $sale->misc_charge);
    }

    public function test_a_discount_is_stored_and_subtracted_from_the_total(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'labor_charge' => '500',
            'misc_charge' => '250.50',
            'discount' => '100',
            'lines' => [
                ['item_id' => null, 'item_name' => 'Oil', 'type' => 'product', 'manually_charged_price' => '4200'],
            ],
        ]))->assertRedirect();

        $sale = Sale::sole();

        $this->assertSame('100.00', $sale->discount);
        $this->assertSame('4850.50', $sale->total_amount);
    }

    public function test_a_blank_discount_is_stored_as_zero(): void
    {
        $this->post(route('sales.store'), $this->payload());

        $this->assertSame('0.00', Sale::sole()->discount);
    }

    public function test_an_entirely_empty_sale_is_rejected(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [], 'labor_charge' => '', 'misc_charge' => '',
        ]))->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    public function test_a_line_without_a_description_is_rejected(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [['item_id' => null, 'item_name' => '', 'type' => 'custom', 'manually_charged_price' => '100']],
        ]))->assertSessionHasErrors('lines.0.item_name');

        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    public function test_a_line_with_an_unknown_type_is_rejected(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [['item_id' => null, 'item_name' => 'Thing', 'type' => 'freebie', 'manually_charged_price' => '100']],
        ]))->assertSessionHasErrors('lines.0.type');
    }

    public function test_a_line_referencing_a_nonexistent_item_is_rejected(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [['item_id' => 99999, 'item_name' => 'Ghost', 'type' => 'product', 'manually_charged_price' => '100']],
        ]))->assertSessionHasErrors('lines.0.item_id');

        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    public function test_a_non_numeric_price_is_rejected(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [['item_id' => null, 'item_name' => 'Thing', 'type' => 'custom', 'manually_charged_price' => 'free']],
        ]))->assertSessionHasErrors('lines.0.manually_charged_price');
    }

    public function test_a_negative_price_is_rejected(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [['item_id' => null, 'item_name' => 'Thing', 'type' => 'custom', 'manually_charged_price' => '-100']],
        ]))->assertSessionHasErrors('lines.0.manually_charged_price');
    }

    public function test_mileage_must_be_a_non_negative_whole_number(): void
    {
        $this->post(route('sales.store'), $this->payload(['mileage' => '-5']))
            ->assertSessionHasErrors('mileage');
    }

    public function test_next_checkup_mileage_must_be_a_non_negative_whole_number(): void
    {
        $this->post(route('sales.store'), $this->payload(['next_checkup_mileage' => '-5']))
            ->assertSessionHasErrors('next_checkup_mileage');
    }

    public function test_next_checkup_mileage_must_be_a_whole_number(): void
    {
        $this->post(route('sales.store'), $this->payload(['next_checkup_mileage' => '90000.5']))
            ->assertSessionHasErrors('next_checkup_mileage');
    }

    public function test_next_checkup_mileage_cannot_exceed_the_visit_odometer_limit(): void
    {
        $this->post(route('sales.store'), $this->payload(['next_checkup_mileage' => 100000000]))
            ->assertSessionHasErrors('next_checkup_mileage');
    }

    public function test_a_failed_checkout_saves_nothing_at_all(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => null, 'item_name' => 'Good line', 'type' => 'custom', 'manually_charged_price' => '100'],
                ['item_id' => null, 'item_name' => '', 'type' => 'custom', 'manually_charged_price' => '200'],
            ],
        ]))->assertSessionHasErrors();

        $this->assertDatabaseCount('sales', 0, 'tenant');
        $this->assertDatabaseCount('sale_items', 0, 'tenant');
    }

    public function test_customer_details_are_optional_for_a_walk_in(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'customer_name' => '', 'phone' => '', 'vehicle_model' => '',
            'vehicle_plate' => '', 'mileage' => '', 'next_checkup_mileage' => '',
        ]))->assertRedirect();

        $this->assertDatabaseCount('sales', 1, 'tenant');
        $this->assertNull(Sale::sole()->mileage);
        $this->assertNull(Sale::sole()->next_checkup_mileage);
    }

    public function test_the_plate_number_is_stored_uppercased_and_trimmed(): void
    {
        $this->post(route('sales.store'), $this->payload(['vehicle_plate' => '  abc-123  ']));

        $this->assertSame('ABC-123', Sale::sole()->vehicle_plate);
    }

    public function test_two_sales_in_the_same_second_get_distinct_invoice_numbers(): void
    {
        $this->post(route('sales.store'), $this->payload());
        $this->post(route('sales.store'), $this->payload());

        $this->assertCount(2, Sale::pluck('invoice_number')->unique());
    }
    /*
    |--------------------------------------------------------------------------
    | Line quantity (PRD §5) — drives stock, never the price
    |--------------------------------------------------------------------------
    */

    public function test_a_line_records_the_quantity_sold(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => null, 'item_name' => 'Oil', 'type' => 'product', 'quantity' => 4, 'manually_charged_price' => '4200'],
            ],
        ]))->assertRedirect();

        $this->assertSame(4, Sale::sole()->lines->first()->quantity);
    }

    public function test_a_line_without_a_quantity_defaults_to_one(): void
    {
        $this->post(route('sales.store'), $this->payload())->assertRedirect();

        $this->assertSame(1, Sale::sole()->lines->first()->quantity);
    }

    public function test_quantity_never_multiplies_the_manually_typed_line_price(): void
    {
        // The single most important rule on this screen: manually_charged_price is
        // the TOTAL for the line, typed by hand. Quantity is inventory bookkeeping
        // and print detail — it must never touch the money.
        $this->post(route('sales.store'), $this->payload([
            'labor_charge' => '',
            'misc_charge' => '',
            'lines' => [
                ['item_id' => null, 'item_name' => 'Oil', 'type' => 'product', 'quantity' => 3, 'manually_charged_price' => '1000'],
            ],
        ]))->assertRedirect();

        $sale = Sale::sole();

        $this->assertSame('1000.00', $sale->lines->first()->manually_charged_price);
        $this->assertSame('1000.00', $sale->total_amount, 'quantity must not multiply the typed price');
        $this->assertSame('1000.00', $sale->lineSubtotal());
    }

    public function test_a_zero_quantity_is_rejected(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => null, 'item_name' => 'Oil', 'type' => 'product', 'quantity' => 0, 'manually_charged_price' => '100'],
            ],
        ]))->assertSessionHasErrors('lines.0.quantity');

        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    public function test_a_fractional_quantity_is_rejected(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => null, 'item_name' => 'Oil', 'type' => 'product', 'quantity' => '1.5', 'manually_charged_price' => '100'],
            ],
        ]))->assertSessionHasErrors('lines.0.quantity');
    }

    public function test_a_blank_quantity_falls_back_to_one_rather_than_failing_the_bill(): void
    {
        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => null, 'item_name' => 'Oil', 'type' => 'product', 'quantity' => '', 'manually_charged_price' => '100'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Sale::sole()->lines->first()->quantity);
    }

    /*
    |--------------------------------------------------------------------------
    | Cashier attribution (PRD §5)
    |--------------------------------------------------------------------------
    */

    public function test_the_signed_in_user_is_recorded_as_the_cashier(): void
    {
        $cashier = User::factory()->manager()->create(['name' => 'Sana at the counter']);

        $this->actingAs($cashier)
            ->post(route('sales.store'), $this->payload())
            ->assertRedirect();

        $sale = Sale::sole();

        $this->assertSame($cashier->id, $sale->cashier_id);
        $this->assertTrue($sale->cashier->is($cashier));
    }

    public function test_deleting_a_user_keeps_their_sales_and_only_clears_the_attribution(): void
    {
        $cashier = User::factory()->manager()->create();

        $this->actingAs($cashier)->post(route('sales.store'), $this->payload())->assertRedirect();

        $sale = Sale::sole();
        $cashier->delete();

        $this->assertDatabaseCount('sales', 1, 'tenant');
        $this->assertNull($sale->refresh()->cashier_id, 'the FK should null out, not cascade');
        $this->assertNull($sale->cashier);
    }

    /*
    |--------------------------------------------------------------------------
    | Hardening — findings from the security review
    |--------------------------------------------------------------------------
    */

    public function test_a_product_line_may_not_point_at_a_repair_item(): void
    {
        // Filing a repair under Products corrupts the dashboard's product/repair split.
        $repair = Item::factory()->repair()->create(['name' => 'Brake Pad Replacement']);

        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => $repair->id, 'item_name' => 'Brake Pad Replacement', 'type' => 'product', 'manually_charged_price' => '3500'],
            ],
        ]))->assertSessionHasErrors('lines.0.type');

        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    public function test_a_repair_line_may_not_point_at_a_product_item(): void
    {
        $product = Item::factory()->create(['name' => 'ZIC 10W-40']);

        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => $product->id, 'item_name' => 'ZIC 10W-40', 'type' => 'repair', 'manually_charged_price' => '4200'],
            ],
        ]))->assertSessionHasErrors('lines.0.type');

        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    public function test_a_line_whose_type_matches_its_item_is_accepted(): void
    {
        $product = Item::factory()->create(['name' => 'ZIC 10W-40']);
        $repair = Item::factory()->repair()->create(['name' => 'Brake Pad Replacement']);

        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => $product->id, 'item_name' => 'ZIC 10W-40', 'type' => 'product', 'manually_charged_price' => '4200'],
                ['item_id' => $repair->id, 'item_name' => 'Brake Pad Replacement', 'type' => 'repair', 'manually_charged_price' => '3500'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('sales', 1, 'tenant');
    }

    public function test_a_deactivated_item_cannot_be_sold(): void
    {
        // It is missing from the picker on purpose; a stale tab must not slip it through.
        $retired = Item::factory()->inactive()->create(['name' => 'Discontinued Oil']);

        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => $retired->id, 'item_name' => 'Discontinued Oil', 'type' => 'product', 'manually_charged_price' => '4200'],
            ],
        ]))->assertSessionHasErrors('lines.0.item_id');

        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    public function test_a_custom_line_is_unaffected_by_the_item_type_cross_check(): void
    {
        $repair = Item::factory()->repair()->create();

        $this->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => $repair->id, 'item_name' => 'Ad-hoc welding', 'type' => 'custom', 'manually_charged_price' => '750'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertNull(Sale::sole()->lines->first()->item_id);
    }

    public function test_a_long_bill_does_not_issue_one_existence_query_per_line(): void
    {
        $items = Item::factory()->count(25)->create();

        $lines = $items->map(fn (Item $item) => [
            'item_id' => $item->id,
            'item_name' => $item->name,
            'type' => 'product',
            'manually_charged_price' => '100',
        ])->all();

        $itemSelects = 0;
        \DB::listen(function (QueryExecuted $query) use (&$itemSelects): void {
            if (preg_match("/^select .*\bfrom .?items.?/i", $query->sql) === 1) {
                $itemSelects++;
            }
        });

        $this->post(route('sales.store'), $this->payload(['lines' => $lines]))->assertRedirect();

        $this->assertLessThanOrEqual(
            4,
            $itemSelects,
            "a 25-line bill must resolve its items in bulk, not one query per line (saw {$itemSelects})"
        );
    }
    /* ---------------------------------------------------------------- */
    /* PRD §5: state preservation must be bulletproof */
    /* ---------------------------------------------------------------- */

    public function test_a_rejected_bill_is_handed_back_with_every_typed_value_intact(): void
    {
        $oil = Item::factory()->create(['name' => 'ZIC 10W-40']);

        // One good line, one line missing its description — the bill is refused.
        $response = $this->from(route('pos.create'))->post(route('sales.store'), $this->payload([
            'customer_name' => 'Ali Raza',
            'vehicle_plate' => 'ABC-123',
            'mileage' => 84500,
            'next_checkup_mileage' => 91234,
            'discount' => '150',
            'lines' => [
                ['item_id' => $oil->id, 'item_name' => 'ZIC 10W-40', 'type' => 'product', 'manually_charged_price' => '4200'],
                ['item_id' => null, 'item_name' => '', 'type' => 'custom', 'manually_charged_price' => '900'],
            ],
        ]));

        $response->assertRedirect(route('pos.create'))->assertSessionHasErrors();

        // Follow the redirect and confirm the counter gets their work back.
        $page = $this->followingRedirects()->get(route('pos.create'));

        $page->assertOk()
            ->assertSee('Ali Raza', false)
            ->assertSee('ABC-123', false)
            ->assertSee('84500', false)
            ->assertSee('next_checkup_mileage\\u0022:\\u002291234\\u0022', false)
            ->assertSee('150', false)
            ->assertSee('4200', false)
            ->assertSee('900', false);
    }

    public function test_the_restored_bill_keeps_each_line_on_its_original_row(): void
    {
        $oil = Item::factory()->create(['name' => 'ZIC 10W-40']);

        $this->from(route('pos.create'))->post(route('sales.store'), $this->payload([
            'lines' => [
                ['item_id' => $oil->id, 'item_name' => 'ZIC 10W-40', 'type' => 'product', 'manually_charged_price' => '4200'],
                ['item_id' => null, 'item_name' => 'Door latch', 'type' => 'custom', 'manually_charged_price' => '900'],
                ['item_id' => null, 'item_name' => '', 'type' => 'custom', 'manually_charged_price' => 'not-a-number'],
            ],
        ]))->assertSessionHasErrors();

        $this->followingRedirects()->get(route('pos.create'))
            ->assertOk()
            // The Alpine cart is seeded from old input, newest row last.
            ->assertSeeInOrder(['4200', 'Door latch', '900'], false);
    }
}
