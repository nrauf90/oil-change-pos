<?php

namespace Tests\Feature;

use App\Filament\Pages\MarginReportPage;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\MarginReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MarginReportTest extends TestCase
{
    use RefreshDatabase;

    private function sell(Item $item, string $charged, int $quantity = 1, ?Sale $sale = null): SaleItem
    {
        return SaleItem::factory()->for($sale ?? Sale::factory()->create(), 'sale')->create([
            'item_id' => $item->id,
            'item_name' => $item->name,
            'type' => $item->type->value,
            'quantity' => $quantity,
            'manually_charged_price' => $charged,
        ]);
    }

    /* ---------------------------------------------------------------- */
    /* The maths */
    /* ---------------------------------------------------------------- */

    public function test_margin_is_the_manually_charged_price_less_the_unit_cost(): void
    {
        $item = Item::factory()->create(['name' => 'ZIC X7 10W-40', 'unit_cost' => 6800]);
        $this->sell($item, '8000');

        $row = (new MarginReport)->rows()->firstWhere('item_name', 'ZIC X7 10W-40');

        $this->assertSame('8000.00', $row['revenue']);
        $this->assertSame('6800.00', $row['cost']);
        $this->assertSame('1200.00', $row['margin']);
    }

    public function test_cost_is_multiplied_by_the_line_quantity(): void
    {
        $item = Item::factory()->create(['name' => 'Oil Filter', 'unit_cost' => 850]);
        // Four filters sold for 3,000 all in — the counter typed one total.
        $this->sell($item, '3000', quantity: 4);

        $row = (new MarginReport)->rows()->firstWhere('item_name', 'Oil Filter');

        $this->assertSame('3000.00', $row['revenue'], 'revenue is the typed line total, never multiplied');
        $this->assertSame('3400.00', $row['cost'], '4 units at 850');
        $this->assertSame('-400.00', $row['margin'], 'this line was sold at a loss');
    }

    public function test_a_line_sold_below_cost_reports_a_negative_margin(): void
    {
        $item = Item::factory()->create(['name' => 'Coolant', 'unit_cost' => 950]);
        $this->sell($item, '500');

        $row = (new MarginReport)->rows()->firstWhere('item_name', 'Coolant');

        $this->assertSame('-450.00', $row['margin']);
        $this->assertTrue($row['below_cost']);
    }

    public function test_margin_percentage_is_relative_to_revenue(): void
    {
        $item = Item::factory()->create(['name' => 'Air Filter', 'unit_cost' => 500]);
        $this->sell($item, '1000');

        $row = (new MarginReport)->rows()->firstWhere('item_name', 'Air Filter');

        $this->assertEqualsWithDelta(50.0, $row['margin_percent'], 0.01);
    }

    public function test_a_zero_revenue_line_does_not_divide_by_zero(): void
    {
        $item = Item::factory()->create(['name' => 'Freebie', 'unit_cost' => 100]);
        $this->sell($item, '0');

        $row = (new MarginReport)->rows()->firstWhere('item_name', 'Freebie');

        $this->assertSame('0.00', $row['revenue']);
        $this->assertSame(0.0, $row['margin_percent']);
    }

    public function test_quantities_and_revenue_accumulate_across_sales_of_the_same_item(): void
    {
        $item = Item::factory()->create(['name' => 'ZIC X7 10W-40', 'unit_cost' => 1000]);
        $this->sell($item, '1500');
        $this->sell($item, '2500', quantity: 2);

        $row = (new MarginReport)->rows()->firstWhere('item_name', 'ZIC X7 10W-40');

        $this->assertSame(3, $row['quantity']);
        $this->assertSame('4000.00', $row['revenue']);
        $this->assertSame('3000.00', $row['cost']);
        $this->assertSame('1000.00', $row['margin']);
    }

    /* ---------------------------------------------------------------- */
    /* Lines with no cost on file */
    /* ---------------------------------------------------------------- */

    public function test_an_item_without_a_unit_cost_reports_revenue_but_no_margin(): void
    {
        $item = Item::factory()->create(['name' => 'Mystery Part', 'unit_cost' => null]);
        $this->sell($item, '2000');

        $row = (new MarginReport)->rows()->firstWhere('item_name', 'Mystery Part');

        $this->assertSame('2000.00', $row['revenue']);
        $this->assertNull($row['cost'], 'no cost on file means no honest margin');
        $this->assertNull($row['margin']);
    }

    public function test_a_custom_line_has_no_inventory_cost(): void
    {
        SaleItem::factory()->custom()->create([
            'item_name' => 'Fixed jammed door latch',
            'manually_charged_price' => '1500',
        ]);

        $row = (new MarginReport)->rows()->firstWhere('item_name', 'Fixed jammed door latch');

        $this->assertSame('1500.00', $row['revenue']);
        $this->assertNull($row['margin']);
    }

    public function test_costed_and_uncosted_revenue_are_reported_separately(): void
    {
        $costed = Item::factory()->create(['name' => 'Costed', 'unit_cost' => 400]);
        $uncosted = Item::factory()->create(['name' => 'Uncosted', 'unit_cost' => null]);

        $this->sell($costed, '1000');
        $this->sell($uncosted, '500');

        $report = new MarginReport;

        $this->assertSame('1500.00', $report->totalRevenue());
        $this->assertSame('1000.00', $report->costedRevenue());
        $this->assertSame('500.00', $report->uncostedRevenue());
        $this->assertSame('400.00', $report->totalCost());
        $this->assertSame('600.00', $report->totalMargin());
    }

    /* ---------------------------------------------------------------- */
    /* The cost snapshot question */
    /* ---------------------------------------------------------------- */

    public function test_deleting_an_item_leaves_its_revenue_but_drops_its_cost(): void
    {
        $item = Item::factory()->create(['name' => 'Discontinued Oil', 'unit_cost' => 500]);
        $this->sell($item, '1200');

        $item->delete();

        $row = (new MarginReport)->rows()->firstWhere('item_name', 'Discontinued Oil');

        $this->assertSame('1200.00', $row['revenue'], 'the sale still happened');
        $this->assertNull($row['cost'], 'the cost is no longer knowable once the item is gone');
    }

    /* ---------------------------------------------------------------- */
    /* Name collisions */
    /* ---------------------------------------------------------------- */

    public function test_a_custom_line_sharing_an_item_name_does_not_borrow_that_items_cost(): void
    {
        $filter = Item::factory()->create(['name' => 'Oil Filter — Honda Civic', 'unit_cost' => 900]);
        $this->sell($filter, '1200');

        // On another bill the counter typed the same text as a free-text line.
        SaleItem::factory()->custom()->create([
            'item_name' => 'Oil Filter — Honda Civic',
            'manually_charged_price' => '1200',
        ]);

        $report = new MarginReport;

        $this->assertSame('2400.00', $report->totalRevenue());
        $this->assertSame('1200.00', $report->costedRevenue(), 'only the linked line can be costed');
        $this->assertSame('1200.00', $report->uncostedRevenue());
        $this->assertSame('900.00', $report->totalCost());
        $this->assertSame('300.00', $report->totalMargin(), 'not 1,500 — the custom line has no cost on file');

        $rows = $report->rows()->where('item_name', 'Oil Filter — Honda Civic');

        $this->assertCount(2, $rows, 'a shared name is not a shared identity');
        $this->assertSame('300.00', $rows->firstWhere('item_id', $filter->id)['margin']);
        $this->assertNull($rows->firstWhere('item_id', null)['cost']);
    }

    public function test_relisting_a_deleted_item_under_the_same_name_does_not_cost_its_old_sales(): void
    {
        $old = Item::factory()->create(['name' => 'Oil Filter', 'unit_cost' => 700]);
        $this->sell($old, '1000');
        $old->delete();

        $relisted = Item::factory()->create(['name' => 'Oil Filter', 'unit_cost' => 400]);
        $this->sell($relisted, '900');

        $report = new MarginReport;

        $this->assertSame('1900.00', $report->totalRevenue());
        $this->assertSame('900.00', $report->costedRevenue(), 'the orphaned sale is not costed by the new item');
        $this->assertSame('1000.00', $report->uncostedRevenue());
        $this->assertSame('500.00', $report->totalMargin());

        $rows = $report->rows()->where('item_name', 'Oil Filter');

        $this->assertCount(2, $rows);
        $this->assertNull($rows->firstWhere('item_id', null)['cost']);
        $this->assertSame('400.00', $rows->firstWhere('item_id', $relisted->id)['cost']);
    }

    public function test_lines_with_no_item_are_still_grouped_by_their_own_names(): void
    {
        SaleItem::factory()->custom()->create(['item_name' => 'Welded exhaust bracket', 'manually_charged_price' => '800']);
        SaleItem::factory()->custom()->create(['item_name' => 'Welded exhaust bracket', 'manually_charged_price' => '200']);
        SaleItem::factory()->custom()->create(['item_name' => 'Fixed jammed door latch', 'manually_charged_price' => '1500']);

        $rows = (new MarginReport)->rows();

        $this->assertCount(2, $rows, 'lines without an item are still told apart by name');
        $this->assertSame('1000.00', $rows->firstWhere('item_name', 'Welded exhaust bracket')['revenue']);
        $this->assertSame('1500.00', $rows->firstWhere('item_name', 'Fixed jammed door latch')['revenue']);
    }

    /* ---------------------------------------------------------------- */
    /* Period windowing */
    /* ---------------------------------------------------------------- */

    public function test_the_report_only_covers_the_requested_window(): void
    {
        $item = Item::factory()->create(['name' => 'ZIC X7 10W-40', 'unit_cost' => 1000]);

        $old = Sale::factory()->create(['created_at' => now()->subMonths(2)]);
        $this->sell($item, '5000', sale: $old);

        $recent = Sale::factory()->create(['created_at' => now()->subDay()]);
        $this->sell($item, '1500', sale: $recent);

        $report = new MarginReport(now()->subWeek(), now());

        $this->assertSame('1500.00', $report->totalRevenue(), 'the two-month-old sale is out of window');
    }

    public function test_an_empty_window_reports_zeroes_rather_than_erroring(): void
    {
        $report = new MarginReport(now()->subWeek(), now());

        $this->assertTrue($report->rows()->isEmpty());
        $this->assertSame('0.00', $report->totalRevenue());
        $this->assertSame('0.00', $report->totalMargin());
        $this->assertSame(0.0, $report->overallMarginPercent());
    }

    /* ---------------------------------------------------------------- */
    /* Ordering */
    /* ---------------------------------------------------------------- */

    public function test_rows_are_ordered_by_revenue_so_the_biggest_earners_lead(): void
    {
        $small = Item::factory()->create(['name' => 'Small Earner', 'unit_cost' => 10]);
        $big = Item::factory()->create(['name' => 'Big Earner', 'unit_cost' => 10]);

        $this->sell($small, '100');
        $this->sell($big, '9000');

        $this->assertSame('Big Earner', (new MarginReport)->rows()->first()['item_name']);
    }

    /* ---------------------------------------------------------------- */
    /* Access */
    /* ---------------------------------------------------------------- */

    public function test_only_an_admin_may_open_the_margin_report(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(MarginReportPage::canAccess());

        $this->actingAs(User::factory()->manager()->create());
        $this->assertFalse(
            MarginReportPage::canAccess(),
            'PRD §1 keeps managers off master margin analytics'
        );

        $this->actingAs(User::factory()->technician()->create());
        $this->assertFalse(MarginReportPage::canAccess());
    }

    public function test_the_margin_page_closes_with_the_reporting_module(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(MarginReportPage::canAccess());

        app(ModuleRegistry::class)->setEnabled('reports', false);

        $this->assertFalse(
            MarginReportPage::canAccess(),
            'reports.view_margins belongs to a module the owner can switch off'
        );
    }

    public function test_the_margin_page_renders_for_an_admin(): void
    {
        $item = Item::factory()->create(['name' => 'ZIC X7 10W-40', 'unit_cost' => 6800]);
        $this->sell($item, '8000');

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(MarginReportPage::class)
            ->assertSuccessful()
            ->assertSee('ZIC X7 10W-40');
    }
}
