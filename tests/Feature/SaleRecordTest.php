<?php

namespace Tests\Feature;

use App\Enums\SaleLineType;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SaleRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_a_sale_persists_with_its_lines(): void
    {
        $sale = Sale::factory()
            ->has(SaleItem::factory()->count(3), 'lines')
            ->create();

        $this->assertCount(3, $sale->refresh()->lines);
    }

    public function test_a_custom_line_needs_no_inventory_item(): void
    {
        $line = SaleItem::factory()->custom()->create([
            'item_name' => 'Fixed jammed passenger door latch',
            'manually_charged_price' => 1500,
        ]);

        $this->assertNull($line->item_id);
        $this->assertSame(SaleLineType::Custom, $line->type);
        $this->assertDatabaseHas('sale_items', ['item_name' => 'Fixed jammed passenger door latch'], 'tenant');
    }

    public function test_deleting_an_inventory_item_preserves_the_historical_sale_line(): void
    {
        $item = Item::factory()->create(['name' => 'ZIC 10W-40']);
        $line = SaleItem::factory()->create([
            'item_id' => $item->id,
            'item_name' => 'ZIC 10W-40',
            'manually_charged_price' => 4200,
        ]);

        $item->delete();
        $line->refresh();

        $this->assertNull($line->item_id, 'the foreign key should null out');
        $this->assertSame('ZIC 10W-40', $line->item_name, 'the name snapshot must survive');
        $this->assertSame('4200.00', $line->manually_charged_price);
    }

    public function test_every_sale_gets_a_unique_sequential_invoice_number(): void
    {
        $first = Sale::factory()->create();
        $second = Sale::factory()->create();

        $this->assertNotEmpty($first->invoice_number);
        $this->assertNotSame($first->invoice_number, $second->invoice_number);
    }

    public function test_a_sale_exposes_its_line_subtotal_without_recomputing_prices(): void
    {
        $sale = Sale::factory()->create(['labor_charge' => 500, 'misc_charge' => 100, 'total_amount' => 2100]);
        SaleItem::factory()->for($sale, 'sale')->create(['manually_charged_price' => 1000]);
        SaleItem::factory()->for($sale, 'sale')->create(['manually_charged_price' => 500]);

        $this->assertSame('1500.00', $sale->refresh()->lineSubtotal());
    }

    public function test_a_sale_builds_a_whatsapp_share_link_from_the_customer_phone(): void
    {
        $sale = Sale::factory()->create(['phone' => '0300-123 4567', 'customer_name' => 'Ali']);

        $this->assertStringContainsString('wa.me/03001234567', $sale->whatsappUrl());
    }

    public function test_a_sale_without_a_phone_has_no_whatsapp_link(): void
    {
        $this->assertNull(Sale::factory()->create(['phone' => null])->whatsappUrl());
    }

    public function test_a_line_defaults_to_a_quantity_of_one(): void
    {
        $line = SaleItem::factory()->create();

        $this->assertSame(1, $line->refresh()->quantity);
    }

    public function test_a_line_can_record_multiple_units_without_changing_its_price(): void
    {
        $line = SaleItem::factory()->create([
            'quantity' => 5,
            'manually_charged_price' => 2000,
        ]);

        $this->assertSame(5, $line->quantity);
        $this->assertSame('2000.00', $line->manually_charged_price);
    }

    /*
    |--------------------------------------------------------------------------
    | Hardening — findings from the security review
    |--------------------------------------------------------------------------
    */

    public function test_the_total_and_invoice_number_are_not_mass_assignable(): void
    {
        // Both are derived server-side. Leaving them fillable is the exact mistake
        // this codebase exists to prevent, even though no caller exploits it today.
        $sale = new Sale([
            'customer_name' => 'Ali',
            'total_amount' => '999999',
            'invoice_number' => 'HACKED-0001',
        ]);

        $this->assertSame('Ali', $sale->customer_name);
        $this->assertNull($sale->total_amount);
        $this->assertNull($sale->invoice_number);
    }

    public function test_invoice_numbers_keep_counting_past_the_four_digit_boundary(): void
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';
        Sale::factory()->create(['invoice_number' => $prefix.'9999']);

        $this->assertSame($prefix.'10000', Sale::nextInvoiceNumber());

        Sale::factory()->create(['invoice_number' => $prefix.'10000']);

        $this->assertSame($prefix.'10001', Sale::nextInvoiceNumber());
    }

    public function test_five_digit_invoice_numbers_do_not_collide_with_each_other(): void
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';
        Sale::factory()->create(['invoice_number' => $prefix.'9999']);

        $first = Sale::factory()->create();
        $second = Sale::factory()->create();

        $this->assertNotSame($first->invoice_number, $second->invoice_number);
    }

    public function test_a_colliding_invoice_number_is_regenerated_instead_of_losing_the_bill(): void
    {
        // Two counters hitting Complete Sale in the same instant both computed the
        // same number. The loser must not blow up and throw away a typed-in bill.
        $taken = Sale::nextInvoiceNumber();
        Sale::factory()->create(['invoice_number' => $taken]);

        $sale = Sale::factory()->make();
        $sale->invoice_number = $taken;
        $sale->save();

        $this->assertTrue($sale->exists);
        $this->assertNotSame($taken, $sale->invoice_number);
        $this->assertSame(2, Sale::count());
    }

    public function test_a_wildcard_in_a_sales_search_is_treated_as_a_literal(): void
    {
        Sale::factory()->create(['customer_name' => 'Ali Raza']);
        Sale::factory()->create(['customer_name' => 'Bilal Khan']);

        $this->assertSame(0, Sale::search('%')->count(), 'a bare % must not return the whole ledger');
        $this->assertSame(0, Sale::search('_')->count());
    }

    public function test_a_literal_wildcard_in_a_customer_name_is_still_findable(): void
    {
        $discounted = Sale::factory()->create(['customer_name' => 'Ali 50% Discount']);
        Sale::factory()->create(['customer_name' => 'Bilal Khan']);

        $this->assertSame([$discounted->id], Sale::search('50%')->pluck('id')->all());
    }

    public function test_sale_line_lookups_are_indexed(): void
    {
        // SQLite does not create indexes for foreign keys, so withCount('lines')
        // and every dashboard rollup would full-scan without these.
        $indexed = collect(Schema::connection('tenant')->getIndexes('sale_items'))
            ->pluck('columns')
            ->flatten()
            ->unique();

        $this->assertTrue($indexed->contains('sale_id'));
        $this->assertTrue($indexed->contains('item_id'));
    }
}
