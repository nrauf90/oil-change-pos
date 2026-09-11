<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    /* ---------------------------------------------------------------- */
    /* The printable invoice */
    /* ---------------------------------------------------------------- */

    public function test_an_invoice_shows_the_customer_and_vehicle_details(): void
    {
        $sale = Sale::factory()->create([
            'customer_name' => 'Ali Raza',
            'phone' => '03001234567',
            'vehicle_model' => 'Toyota Corolla 2018',
            'vehicle_plate' => 'ABC-123',
            'mileage' => 84500,
            'next_checkup_mileage' => 90000,
        ]);

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee($sale->invoice_number)
            ->assertSee('Ali Raza')
            ->assertSee('03001234567')
            ->assertSee('Toyota Corolla 2018')
            ->assertSee('ABC-123')
            ->assertSee('Visit odometer reading')
            ->assertSee('84,500 km')
            ->assertSee('Next checkup mileage')
            ->assertSee('90,000 km');
    }

    public function test_the_invoice_pdf_lists_visit_and_next_checkup_mileage(): void
    {
        $sale = Sale::factory()->create([
            'mileage' => 84500,
            'next_checkup_mileage' => 90000,
        ]);

        $pdfHtml = view('sales.pdf', ['sale' => $sale])->render();

        $this->assertStringContainsString('Visit odometer reading', $pdfHtml);
        $this->assertStringContainsString('84,500 km', $pdfHtml);
        $this->assertStringContainsString('Next checkup mileage', $pdfHtml);
        $this->assertStringContainsString('90,000 km', $pdfHtml);
    }

    public function test_an_invoice_lists_every_line_at_the_price_that_was_charged(): void
    {
        $sale = Sale::factory()->create(['total_amount' => 5050]);
        SaleItem::factory()->for($sale, 'sale')->create([
            'item_name' => 'ZIC 10W-40', 'manually_charged_price' => 4200,
        ]);
        SaleItem::factory()->for($sale, 'sale')->repair()->create([
            'item_name' => 'Brake Pad Replacement', 'manually_charged_price' => 850,
        ]);

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('ZIC 10W-40')
            ->assertSee('4,200.00')
            ->assertSee('Brake Pad Replacement')
            ->assertSee('850.00');
    }

    public function test_an_invoice_never_displays_the_inventory_unit_cost(): void
    {
        $item = Item::factory()->create(['name' => 'ZIC 10W-40', 'unit_cost' => 9999]);
        $sale = Sale::factory()->create(['total_amount' => 100]);
        SaleItem::factory()->for($sale, 'sale')->create([
            'item_id' => $item->id, 'item_name' => 'ZIC 10W-40', 'manually_charged_price' => 100,
        ]);

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('100.00')
            ->assertDontSee('9,999.00')
            ->assertDontSee('9999.00');
    }

    public function test_an_invoice_shows_labor_and_misc_charges_and_the_grand_total(): void
    {
        $sale = Sale::factory()->create([
            'labor_charge' => 500, 'misc_charge' => 250, 'total_amount' => 1750,
        ]);
        SaleItem::factory()->for($sale, 'sale')->create(['manually_charged_price' => 1000]);

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('500.00')
            ->assertSee('250.00')
            ->assertSee('1,750.00');
    }

    /**
     * The old shape: a historical sale never had a discount, only labor/misc.
     * It must keep showing exactly what it always showed, with no stray
     * "Discount" line implying a discount that was never applied.
     */
    public function test_an_invoice_with_historical_labor_and_misc_shows_no_discount_line(): void
    {
        $sale = Sale::factory()->create([
            'labor_charge' => 500, 'misc_charge' => 250, 'discount' => 0, 'total_amount' => 1750,
        ]);
        SaleItem::factory()->for($sale, 'sale')->create(['manually_charged_price' => 1000]);

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('Labor')
            ->assertSee('Miscellaneous')
            ->assertDontSee('Discount');

        $pdfHtml = view('sales.pdf', ['sale' => $sale])->render();

        $this->assertStringContainsString('Labor', $pdfHtml);
        $this->assertStringContainsString('Miscellaneous', $pdfHtml);
        $this->assertStringNotContainsString('Discount', $pdfHtml);
    }

    /**
     * The new shape: a sale rung up after discount replaced labor/misc always
     * carries 0 for both — the printed breakdown must show Discount only.
     */
    public function test_an_invoice_with_a_discount_and_no_labor_or_misc_shows_only_the_discount_line(): void
    {
        $sale = Sale::factory()->create([
            'labor_charge' => 0, 'misc_charge' => 0, 'discount' => 150, 'total_amount' => 850,
        ]);
        SaleItem::factory()->for($sale, 'sale')->create(['manually_charged_price' => 1000]);

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('Discount')
            ->assertSee('150.00')
            ->assertDontSee('Labor')
            ->assertDontSee('Miscellaneous');

        $pdfHtml = view('sales.pdf', ['sale' => $sale])->render();

        $this->assertStringContainsString('Discount', $pdfHtml);
        $this->assertStringContainsString('150.00', $pdfHtml);
        $this->assertStringNotContainsString('Labor', $pdfHtml);
        $this->assertStringNotContainsString('Miscellaneous', $pdfHtml);
    }

    public function test_a_walk_in_invoice_renders_without_customer_details(): void
    {
        $sale = Sale::factory()->create([
            'customer_name' => null, 'phone' => null, 'vehicle_model' => null,
            'vehicle_plate' => null, 'mileage' => null,
        ]);

        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Walk-in');
    }

    public function test_an_invoice_offers_a_whatsapp_link_when_a_phone_was_captured(): void
    {
        $sale = Sale::factory()->create(['phone' => '03001234567']);

        $this->get(route('sales.show', $sale))->assertOk()->assertSee('wa.me/03001234567', false);
    }

    public function test_an_invoice_hides_the_whatsapp_link_when_no_phone_was_captured(): void
    {
        $sale = Sale::factory()->create(['phone' => null]);

        $this->get(route('sales.show', $sale))->assertOk()->assertDontSee('wa.me');
    }

    public function test_an_unknown_invoice_returns_404(): void
    {
        $this->get(route('sales.show', 999))->assertNotFound();
    }

    public function test_an_invoice_can_be_downloaded_as_a_pdf(): void
    {
        $sale = Sale::factory()->create();
        SaleItem::factory()->for($sale, 'sale')->create(['item_name' => 'ZIC 10W-40']);

        $response = $this->get(route('sales.pdf', $sale));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString($sale->invoice_number.'.pdf', (string) $response->headers->get('content-disposition'));
    }

    /* ---------------------------------------------------------------- */
    /* Sales history */
    /* ---------------------------------------------------------------- */

    public function test_the_sales_list_shows_recorded_invoices_newest_first(): void
    {
        $older = Sale::factory()->create(['customer_name' => 'First Customer']);
        $newer = Sale::factory()->create(['customer_name' => 'Second Customer']);

        $this->get(route('sales.index'))
            ->assertOk()
            ->assertSee($older->invoice_number)
            ->assertSee($newer->invoice_number)
            ->assertSeeInOrder([$newer->invoice_number, $older->invoice_number]);
    }

    public function test_the_sales_list_has_an_empty_state(): void
    {
        $this->get(route('sales.index'))->assertOk()->assertSee('No sales recorded yet.');
    }

    public function test_sales_can_be_searched_by_customer_name(): void
    {
        $wanted = Sale::factory()->create(['customer_name' => 'Ali Raza']);
        $other = Sale::factory()->create(['customer_name' => 'Bilal Khan']);

        $this->get(route('sales.index', ['q' => 'Ali']))
            ->assertOk()
            ->assertSee($wanted->invoice_number)
            ->assertDontSee($other->invoice_number);
    }

    public function test_sales_can_be_searched_by_plate_number(): void
    {
        $wanted = Sale::factory()->create(['vehicle_plate' => 'ABC-123']);
        $other = Sale::factory()->create(['vehicle_plate' => 'XYZ-999']);

        $this->get(route('sales.index', ['q' => 'ABC-123']))
            ->assertOk()
            ->assertSee($wanted->invoice_number)
            ->assertDontSee($other->invoice_number);
    }

    public function test_sales_can_be_searched_by_invoice_number(): void
    {
        $wanted = Sale::factory()->create();
        $other = Sale::factory()->create();

        $this->get(route('sales.index', ['q' => $wanted->invoice_number]))
            ->assertOk()
            ->assertSee($wanted->invoice_number)
            ->assertDontSee($other->invoice_number);
    }

    public function test_sales_can_be_searched_by_phone_number(): void
    {
        $wanted = Sale::factory()->create(['phone' => '03001234567']);
        $other = Sale::factory()->create(['phone' => '03119876543']);

        $this->get(route('sales.index', ['q' => '0300123']))
            ->assertOk()
            ->assertSee($wanted->invoice_number)
            ->assertDontSee($other->invoice_number);
    }

    public function test_the_sales_list_counts_the_lines_on_each_invoice(): void
    {
        $sale = Sale::factory()->has(SaleItem::factory()->count(3), 'lines')->create();

        $this->get(route('sales.index'))
            ->assertOk()
            ->assertSee($sale->invoice_number);
    }

    public function test_a_sale_can_be_deleted_along_with_its_lines(): void
    {
        $sale = Sale::factory()->has(SaleItem::factory()->count(2), 'lines')->create();

        $this->delete(route('sales.destroy', $sale))
            ->assertRedirect(route('sales.index'));

        $this->assertDatabaseMissing('sales', ['id' => $sale->id], 'tenant');
        $this->assertDatabaseCount('sale_items', 0, 'tenant');
    }
    /* ---------------------------------------------------------------- */
    /* Quantity and cashier attribution */
    /* ---------------------------------------------------------------- */

    public function test_an_invoice_shows_the_quantity_when_more_than_one_was_sold(): void
    {
        $sale = Sale::factory()->create();
        SaleItem::factory()->for($sale, 'sale')->create([
            'item_name' => 'Oil Filter', 'quantity' => 4, 'manually_charged_price' => 3000,
        ]);

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('Oil Filter')
            ->assertSee('&times; 4', false);
    }

    public function test_an_invoice_does_not_clutter_single_quantity_lines(): void
    {
        $sale = Sale::factory()->create();
        SaleItem::factory()->for($sale, 'sale')->create([
            'item_name' => 'Engine Tuning', 'quantity' => 1, 'manually_charged_price' => 5500,
        ]);

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertDontSee('&times; 1', false);
    }

    public function test_the_quantity_never_multiplies_the_charged_price_on_screen(): void
    {
        $sale = Sale::factory()->create(['total_amount' => 3000]);
        SaleItem::factory()->for($sale, 'sale')->create([
            'item_name' => 'Oil Filter', 'quantity' => 4, 'manually_charged_price' => 3000,
        ]);

        $this->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('3,000.00')
            ->assertDontSee('12,000.00');
    }

    public function test_an_invoice_names_the_cashier_who_rang_it_up(): void
    {
        $cashier = User::factory()->manager()->create(['name' => 'Bilal Khan']);
        $sale = Sale::factory()->create(['cashier_id' => $cashier->id]);

        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Bilal Khan');
    }

    public function test_an_invoice_renders_when_the_cashier_account_has_been_removed(): void
    {
        $cashier = User::factory()->manager()->create();
        $sale = Sale::factory()->create(['cashier_id' => $cashier->id]);

        $cashier->delete();

        $this->get(route('sales.show', $sale->refresh()))->assertOk();
    }

    public function test_the_sales_list_shows_who_rang_up_each_invoice(): void
    {
        $cashier = User::factory()->manager()->create(['name' => 'Bilal Khan']);
        $sale = Sale::factory()->create(['cashier_id' => $cashier->id]);

        $this->get(route('sales.index'))->assertOk()->assertSee('Bilal Khan');
    }

    /* ---------------------------------------------------------------- */
    /* Delete controls are only rendered for staff who may use them */
    /* ---------------------------------------------------------------- */

    public function test_an_admin_sees_a_delete_control_on_the_sales_list(): void
    {
        $sale = Sale::factory()->create();

        // The destroy URL is the show URL under another verb, so the button
        // itself is what has to be asserted on.
        $this->get(route('sales.index'))
            ->assertOk()
            ->assertSee('>Delete</button>', escape: false);
    }

    public function test_a_manager_sees_no_delete_control_on_the_sales_list(): void
    {
        $sale = Sale::factory()->create();

        // A Manager is deliberately denied sales.delete, so the button would 403.
        $this->actingAs(User::factory()->manager()->create());

        $this->get(route('sales.index'))
            ->assertOk()
            ->assertSee($sale->invoice_number)
            ->assertDontSee('>Delete</button>', escape: false);
    }

    /* ---------------------------------------------------------------- */
    /* Cashier attribution can never come from the request body */
    /* ---------------------------------------------------------------- */

    public function test_a_sale_will_not_mass_assign_a_cashier(): void
    {
        $sale = new Sale(['cashier_id' => 4242, 'customer_name' => 'Ali Raza']);

        $this->assertNull(
            $sale->cashier_id,
            'attribution is a server-side fact, it must never be fillable'
        );
        $this->assertSame('Ali Raza', $sale->customer_name);
    }

    public function test_a_crafted_cashier_id_in_the_checkout_payload_is_ignored(): void
    {
        $someoneElse = User::factory()->manager()->create();
        $cashier = User::factory()->manager()->create();

        $this->actingAs($cashier);

        $this->post(route('sales.store'), [
            'cashier_id' => $someoneElse->id,
            'customer_name' => 'Ali Raza',
            'lines' => [
                ['type' => 'custom', 'item_name' => 'Wiper blade', 'quantity' => 1, 'manually_charged_price' => '500'],
            ],
        ])->assertRedirect();

        $sale = Sale::latest('id')->sole();

        $this->assertSame($cashier->id, $sale->cashier_id);
    }

    /* ---------------------------------------------------------------- */
    /* Array-valued search terms (?q[]=…) */
    /* ---------------------------------------------------------------- */

    public function test_an_array_search_term_on_the_sales_list_is_ignored(): void
    {
        $sale = Sale::factory()->create(['customer_name' => 'Ali Raza']);

        // Casting an array to a string would search for the literal "Array"
        // and emit a PHP warning on every such request.
        $this->get('/sales?q[]=Ali')
            ->assertOk()
            ->assertSee($sale->invoice_number)
            ->assertDontSee('Array');
    }

    public function test_an_array_search_term_on_the_service_history_lookup_is_ignored(): void
    {
        $this->get('/service-history?q[]=0300')
            ->assertOk()
            ->assertSee('Enter a phone number or licence plate')
            ->assertDontSee('Array');
    }

    public function test_an_array_plate_filter_on_the_inspection_list_is_ignored(): void
    {
        $this->get('/inspections?plate[]=ABC')
            ->assertOk()
            ->assertSee('No inspections logged yet. Start one from the workshop floor.')
            ->assertDontSee('Array');
    }
}
