<?php

namespace Tests\Feature;

use App\Enums\UnitOfMeasure;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Bulk consumables: oil bought by the carton and poured by the litre, AC gas
 * bought by the cylinder and charged by the kilogram.
 *
 * The shop needs to know how much is left, so the amount drawn is recorded
 * against the sale — but it is a stock record, not a billing line, and must
 * never reach the customer's invoice.
 */
class MeasuredStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    private function oil(array $overrides = []): Item
    {
        return Item::factory()->create(array_merge([
            'name' => 'ZIC X7 10W-40',
            'unit_of_measure' => UnitOfMeasure::Litre,
            'pack_label' => 'Carton',
            'units_per_pack' => 4,     // 4 bottles to a carton
            'measure_per_unit' => 4,   // 4 litres to a bottle
            'stock_level' => 0,
        ], $overrides));
    }

    private function gas(array $overrides = []): Item
    {
        return Item::factory()->repair()->create(array_merge([
            'name' => 'AC Gas Refill',
            'unit_of_measure' => UnitOfMeasure::Kilogram,
            'pack_label' => 'Cylinder',
            'units_per_pack' => 1,
            'measure_per_unit' => 13,  // a 13 kg cylinder
            'stock_level' => 13,
        ], $overrides));
    }

    /* ---------------------------------------------------------------- */
    /* How stock is described */
    /* ---------------------------------------------------------------- */

    public function test_an_item_is_counted_in_pieces_unless_told_otherwise(): void
    {
        $this->assertSame(UnitOfMeasure::Piece, Item::factory()->create()->unit_of_measure);
    }

    public function test_oil_is_held_in_litres(): void
    {
        $oil = $this->oil();

        $this->assertSame(UnitOfMeasure::Litre, $oil->unit_of_measure);
        $this->assertTrue($oil->isMeasured());
    }

    public function test_a_piece_item_is_not_measured(): void
    {
        $this->assertFalse(Item::factory()->create()->isMeasured());
    }

    public function test_a_pack_reports_how_much_it_contains(): void
    {
        // A carton is 4 bottles of 4 litres.
        $this->assertSame('16.000', $this->oil()->packContains());
        $this->assertSame('13.000', $this->gas(['name' => 'AC Gas (Cylinder)'])->packContains());
    }

    public function test_an_item_without_pack_details_reports_no_pack_size(): void
    {
        $this->assertNull($this->oil(['units_per_pack' => null, 'measure_per_unit' => null])->packContains());
    }

    /* ---------------------------------------------------------------- */
    /* Receiving stock */
    /* ---------------------------------------------------------------- */

    public function test_receiving_two_cartons_adds_thirty_two_litres(): void
    {
        $oil = $this->oil(['stock_level' => 0]);

        $oil->receivePacks(2);

        $this->assertSame('32.000', $oil->refresh()->stock_level);
    }

    public function test_receiving_a_cylinder_adds_thirteen_kilograms(): void
    {
        $gas = $this->gas(['stock_level' => 0]);

        $gas->receivePacks(1);

        $this->assertSame('13.000', $gas->refresh()->stock_level);
    }

    public function test_receiving_stock_adds_to_what_is_already_there(): void
    {
        $oil = $this->oil(['stock_level' => 5.5]);

        $oil->receivePacks(1);

        $this->assertSame('21.500', $oil->refresh()->stock_level);
    }

    public function test_a_measured_amount_can_be_received_directly_without_packs(): void
    {
        $oil = $this->oil(['stock_level' => 10]);

        $oil->receiveMeasure('2.5');

        $this->assertSame('12.500', $oil->refresh()->stock_level);
    }

    /* ---------------------------------------------------------------- */
    /* Drawing stock down on a sale */
    /* ---------------------------------------------------------------- */

    /** @param array<string, mixed> $line */
    private function checkout(array $line, array $saleOverrides = []): void
    {
        $r = $this->post(route('sales.store'), array_merge([
            'customer_name' => 'Ali Raza',
            'labor_charge' => '',
            'misc_charge' => '',
            'lines' => [$line],
        ], $saleOverrides));

        // Every checkout via this helper is expected to go through; without the
        // guard a 500 would read as "stock simply did not move".
        $r->assertSessionHasNoErrors()->assertRedirect();
    }

    /**
     * For the cases that are supposed to be refused.
     *
     * @param  array<string, mixed>  $line
     */
    private function attemptCheckout(array $line): TestResponse
    {
        return $this->post(route('sales.store'), [
            'customer_name' => 'Ali Raza',
            'labor_charge' => '',
            'misc_charge' => '',
            'lines' => [$line],
        ]);
    }

    public function test_selling_two_and_a_half_litres_takes_that_much_off_the_shelf(): void
    {
        $oil = $this->oil(['stock_level' => 32]);

        $this->checkout([
            'item_id' => $oil->id,
            'item_name' => $oil->name,
            'type' => 'product',
            'quantity' => 1,
            'dispensed_quantity' => '2.5',
            'manually_charged_price' => '1800',
        ]);

        $this->assertSame('29.500', $oil->refresh()->stock_level);
    }

    public function test_an_ac_gas_refill_draws_down_the_cylinder(): void
    {
        $gas = $this->gas(['stock_level' => 13]);

        $this->checkout([
            'item_id' => $gas->id,
            'item_name' => $gas->name,
            'type' => 'repair',
            'quantity' => 1,
            'dispensed_quantity' => '1.5',
            'manually_charged_price' => '3500',
        ]);

        $this->assertSame('11.500', $gas->refresh()->stock_level);
    }

    public function test_a_repair_may_carry_measured_stock(): void
    {
        // The old rule — "a repair task does not carry stock" — has to give way:
        // an AC gas refill is labour that empties a cylinder.
        $gas = $this->gas();

        $this->assertTrue($gas->isMeasured());
        $this->assertSame('13.000', $gas->stock_level);
    }

    public function test_a_piece_item_still_deducts_by_its_line_quantity(): void
    {
        $filter = Item::factory()->create([
            'name' => 'Oil Filter',
            'unit_of_measure' => UnitOfMeasure::Piece,
            'stock_level' => 10,
        ]);

        $this->checkout([
            'item_id' => $filter->id,
            'item_name' => $filter->name,
            'type' => 'product',
            'quantity' => 3,
            'manually_charged_price' => '2400',
        ]);

        $this->assertDatabaseCount('sales', 1, 'tenant');
        $this->assertSame('7.000', $filter->refresh()->stock_level);
    }

    public function test_a_measured_line_ignores_the_piece_quantity_when_deducting(): void
    {
        $oil = $this->oil(['stock_level' => 20]);

        // quantity 4 would wrongly take 4 litres; the dispensed figure rules.
        $this->checkout([
            'item_id' => $oil->id,
            'item_name' => $oil->name,
            'type' => 'product',
            'quantity' => 4,
            'dispensed_quantity' => '1',
            'manually_charged_price' => '900',
        ]);

        $this->assertSame('19.000', $oil->refresh()->stock_level);
    }

    public function test_a_measured_line_with_no_dispensed_amount_moves_no_stock(): void
    {
        $oil = $this->oil(['stock_level' => 20]);

        $this->checkout([
            'item_id' => $oil->id,
            'item_name' => $oil->name,
            'type' => 'product',
            'quantity' => 1,
            'manually_charged_price' => '900',
        ]);

        $this->assertSame('20.000', $oil->refresh()->stock_level);
    }

    public function test_the_dispensed_amount_is_stored_against_the_sale_line(): void
    {
        $oil = $this->oil(['stock_level' => 32]);

        $this->checkout([
            'item_id' => $oil->id,
            'item_name' => $oil->name,
            'type' => 'product',
            'quantity' => 1,
            'dispensed_quantity' => '3.5',
            'manually_charged_price' => '2600',
        ]);

        $this->assertSame('3.500', Sale::sole()->lines->first()->dispensed_quantity);
    }

    public function test_a_fractional_litre_is_not_rounded_away(): void
    {
        $oil = $this->oil(['stock_level' => 10]);

        $this->checkout([
            'item_id' => $oil->id,
            'item_name' => $oil->name,
            'type' => 'product',
            'quantity' => 1,
            'dispensed_quantity' => '0.25',
            'manually_charged_price' => '300',
        ]);

        $this->assertSame('9.750', $oil->refresh()->stock_level);
    }

    public function test_a_negative_dispensed_amount_is_rejected(): void
    {
        $oil = $this->oil();

        $this->attemptCheckout([
            'item_id' => $oil->id,
            'item_name' => $oil->name,
            'type' => 'product',
            'quantity' => 1,
            'dispensed_quantity' => '-5',
            'manually_charged_price' => '300',
        ])->assertSessionHasErrors('lines.0.dispensed_quantity');

        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    public function test_running_the_cylinder_dry_does_not_block_the_sale(): void
    {
        $gas = $this->gas(['stock_level' => 1]);

        $this->checkout([
            'item_id' => $gas->id,
            'item_name' => $gas->name,
            'type' => 'repair',
            'quantity' => 1,
            'dispensed_quantity' => '2',
            'manually_charged_price' => '3500',
        ]);

        // The gas is already in the customer's car. A drifted count must never
        // stop the shop billing them; the negative is the cue to recount.
        $this->assertDatabaseCount('sales', 1, 'tenant');
        $this->assertSame('-1.000', $gas->refresh()->stock_level);
    }

    /* ---------------------------------------------------------------- */
    /* It is a stock record, not a billing line */
    /* ---------------------------------------------------------------- */

    public function test_the_dispensed_amount_never_appears_on_the_printed_invoice(): void
    {
        $sale = Sale::factory()->create();
        SaleItem::factory()->for($sale, 'sale')->create([
            'item_name' => 'ZIC X7 10W-40',
            'quantity' => 1,
            'dispensed_quantity' => '3.5',
            'manually_charged_price' => 2600,
        ]);

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('ZIC X7 10W-40')
            ->assertDontSee('3.5')
            ->assertDontSee('3.500')
            ->assertDontSee('litre', false)
            ->assertDontSee('Litres');
    }

    public function test_the_dispensed_amount_never_appears_on_the_pdf(): void
    {
        $sale = Sale::factory()->create();
        SaleItem::factory()->for($sale, 'sale')->create([
            'item_name' => 'ZIC X7 10W-40',
            'dispensed_quantity' => '3.5',
            'manually_charged_price' => 2600,
        ]);

        $pdf = $this->get(route('sales.pdf', $sale));

        $pdf->assertOk();
        $this->assertStringNotContainsString('3.500', $pdf->getContent());
        $this->assertStringNotContainsString('3.5 L', $pdf->getContent());
    }

    /* ---------------------------------------------------------------- */
    /* Knowing what is left */
    /* ---------------------------------------------------------------- */

    public function test_low_stock_is_judged_in_the_items_own_unit(): void
    {
        $nearlyOut = $this->oil(['stock_level' => 2, 'low_stock_alert' => 5]);
        $plenty = $this->oil(['name' => 'Other Oil', 'stock_level' => 40, 'low_stock_alert' => 5]);

        $this->assertTrue($nearlyOut->isLowOnStock());
        $this->assertFalse($plenty->isLowOnStock());
    }

    public function test_stock_reads_back_with_its_unit(): void
    {
        $this->assertSame('32.000 L', $this->oil(['stock_level' => 32])->stockLabel());
        $this->assertSame('13.000 kg', $this->gas()->stockLabel());
        $this->assertSame('7', Item::factory()->create(['stock_level' => 7])->stockLabel());
    }

    public function test_an_untracked_item_has_no_stock_label(): void
    {
        $this->assertNull(Item::factory()->create(['stock_level' => null])->stockLabel());
    }
}
