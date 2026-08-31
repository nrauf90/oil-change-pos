<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Enums\SaleLineType;
use App\Enums\UnitOfMeasure;
use App\Filament\Pages\ConsumptionReportPage;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\ConsumptionReport;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Quantity will be added for record to see how much Litres we sell and how
 * much left." — the shop owner.
 *
 * Oil is bought by the carton and poured by the litre; AC gas comes in 13 kg
 * cylinders and goes out a kilo or two at a time. The one thing this report
 * must never do is add those two together.
 */
class ConsumptionReportTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------- */
    /* Fixtures */
    /* ---------------------------------------------------------------- */

    private function oil(array $overrides = []): Item
    {
        return Item::factory()->create([
            'name' => 'ZIC X7 10W-40',
            'type' => ItemType::Product,
            'unit_of_measure' => UnitOfMeasure::Litre,
            'pack_label' => 'Carton',
            'units_per_pack' => 4,
            'measure_per_unit' => 4,
            'stock_level' => 28,
            ...$overrides,
        ]);
    }

    private function gas(array $overrides = []): Item
    {
        return Item::factory()->create([
            'name' => 'AC Gas Refill',
            'type' => ItemType::Repair,
            'unit_of_measure' => UnitOfMeasure::Kilogram,
            'pack_label' => 'Cylinder',
            'units_per_pack' => 1,
            'measure_per_unit' => 13,
            'stock_level' => 13,
            ...$overrides,
        ]);
    }

    /** A line that drew bulk stock. `dispensed_quantity` is the figure reported. */
    private function dispense(Item $item, string $amount, ?Sale $sale = null): SaleItem
    {
        return SaleItem::factory()->for($sale ?? Sale::factory()->create(), 'sale')->create([
            'item_id' => $item->id,
            'item_name' => $item->name,
            'type' => $item->type === ItemType::Repair ? SaleLineType::Repair : SaleLineType::Product,
            'quantity' => 1,
            'dispensed_quantity' => $amount,
        ]);
    }

    /** A piece-counted line: no dispensed amount, just a count of things. */
    private function sellPieces(Item $item, int $quantity, ?Sale $sale = null): SaleItem
    {
        return SaleItem::factory()->for($sale ?? Sale::factory()->create(), 'sale')->create([
            'item_id' => $item->id,
            'item_name' => $item->name,
            'type' => SaleLineType::Product,
            'quantity' => $quantity,
            'dispensed_quantity' => null,
        ]);
    }

    private function litreRow(ConsumptionReport $report, string $name): array
    {
        return $report->rowsForUnit(UnitOfMeasure::Litre)->firstWhere('item_name', $name) ?? [];
    }

    /* ---------------------------------------------------------------- */
    /* How much we sold */
    /* ---------------------------------------------------------------- */

    public function test_dispensed_litres_are_summed_per_item_over_the_period(): void
    {
        $oil = $this->oil();

        $this->dispense($oil, '4.000');
        $this->dispense($oil, '3.500');
        $this->dispense($oil, '0.500');

        $row = $this->litreRow(new ConsumptionReport, 'ZIC X7 10W-40');

        $this->assertSame('8.000', $row['dispensed']);
        $this->assertSame('8.000 L', $row['dispensed_label']);
    }

    public function test_the_number_of_sales_an_item_appeared_on_is_counted_per_sale_not_per_line(): void
    {
        $oil = $this->oil();

        $sale = Sale::factory()->create();
        // One customer, two top-ups on the same bill.
        $this->dispense($oil, '3.000', $sale);
        $this->dispense($oil, '1.000', $sale);
        $this->dispense($oil, '4.000');

        $row = $this->litreRow(new ConsumptionReport, 'ZIC X7 10W-40');

        $this->assertSame('8.000', $row['dispensed']);
        $this->assertSame(2, $row['sale_count'], 'three lines, but only two bills');
    }

    public function test_a_sale_outside_the_period_is_excluded(): void
    {
        $oil = $this->oil();

        $old = Sale::factory()->create(['created_at' => now()->subMonths(2)]);
        $this->dispense($oil, '12.000', $old);

        $recent = Sale::factory()->create(['created_at' => now()->subDay()]);
        $this->dispense($oil, '4.500', $recent);

        $report = new ConsumptionReport(now()->subWeek()->startOfDay(), now());
        $row = $this->litreRow($report, 'ZIC X7 10W-40');

        $this->assertSame('4.500', $row['dispensed'], 'the two-month-old sale is out of window');
        $this->assertSame(1, $row['sale_count']);
    }

    public function test_fractional_litres_are_not_rounded_away(): void
    {
        $oil = $this->oil();

        $this->dispense($oil, '0.250');
        $this->dispense($oil, '0.100');
        $this->dispense($oil, '0.050');

        $row = $this->litreRow(new ConsumptionReport, 'ZIC X7 10W-40');

        $this->assertSame('0.400', $row['dispensed'], 'a quarter litre is a real amount of oil');
    }

    public function test_a_line_that_drew_no_bulk_stock_adds_nothing_to_the_dispensed_total(): void
    {
        $oil = $this->oil();

        $this->dispense($oil, '4.000');
        // A line billed against the oil but with nothing poured — a top-up credit.
        $this->sellPieces($oil, 1);

        $row = $this->litreRow(new ConsumptionReport, 'ZIC X7 10W-40');

        $this->assertSame('4.000', $row['dispensed']);
    }

    /* ---------------------------------------------------------------- */
    /* Litres and kilograms are different things */
    /* ---------------------------------------------------------------- */

    public function test_kilograms_and_litres_are_reported_separately_and_never_summed_together(): void
    {
        $oil = $this->oil();
        $gas = $this->gas();

        $this->dispense($oil, '32.000');
        $this->dispense($gas, '13.000');

        $report = new ConsumptionReport;
        $groups = $report->measuredGroups();

        $this->assertSame(['litre', 'kilogram'], $groups->keys()->all());
        $this->assertSame('32.000', $groups['litre']['total_dispensed']);
        $this->assertSame('13.000', $groups['kilogram']['total_dispensed']);

        // The whole point: 32 L and 13 kg are not 45 of anything.
        $this->assertSame(
            ['litre' => '32.000', 'kilogram' => '13.000'],
            $report->totalsByUnit(),
            'every total is reported inside exactly one unit'
        );
    }

    public function test_a_row_only_ever_appears_under_its_own_unit(): void
    {
        $this->oil();
        $gas = $this->gas();

        $this->dispense($gas, '1.500');

        $report = new ConsumptionReport;

        $this->assertSame(['ZIC X7 10W-40'], $report->rowsForUnit(UnitOfMeasure::Litre)->pluck('item_name')->all());
        $this->assertSame(['AC Gas Refill'], $report->rowsForUnit(UnitOfMeasure::Kilogram)->pluck('item_name')->all());
    }

    /* ---------------------------------------------------------------- */
    /* How much is left */
    /* ---------------------------------------------------------------- */

    public function test_remaining_stock_is_the_items_current_balance_not_a_period_figure(): void
    {
        $oil = $this->oil(['stock_level' => 28]);

        $old = Sale::factory()->create(['created_at' => now()->subMonths(2)]);
        $this->dispense($oil, '12.000', $old);

        $report = new ConsumptionReport(now()->subWeek()->startOfDay(), now());
        $row = $this->litreRow($report, 'ZIC X7 10W-40');

        $this->assertSame('0.000', $row['dispensed'], 'nothing was poured inside the window');
        $this->assertTrue($row['stock_tracked']);
        $this->assertSame('28.000', $row['remaining'], 'stock on hand is now, not in the window');
        $this->assertSame('28.000 L', $row['remaining_label']);
        $this->assertSame('Stock on hand right now', $report->remainingCaption());
    }

    public function test_remaining_stock_is_also_expressed_in_packs_when_packaging_is_described(): void
    {
        $this->oil(['stock_level' => 28]);

        $row = $this->litreRow(new ConsumptionReport, 'ZIC X7 10W-40');

        $this->assertSame('16.000', $row['pack_contains'], 'a carton is 4 bottles of 4 litres');
        $this->assertSame('1.75', $row['remaining_packs']);
        $this->assertSame('1.75 Cartons', $row['remaining_packs_label']);
    }

    public function test_a_single_remaining_pack_is_labelled_in_the_singular(): void
    {
        $this->gas(['stock_level' => 13]);

        $row = (new ConsumptionReport)->rowsForUnit(UnitOfMeasure::Kilogram)->firstWhere('item_name', 'AC Gas Refill');

        $this->assertSame('1.00 Cylinder', $row['remaining_packs_label']);
    }

    public function test_an_item_without_packaging_reports_no_pack_figure(): void
    {
        $this->oil(['pack_label' => null, 'units_per_pack' => null, 'measure_per_unit' => null]);

        $row = $this->litreRow(new ConsumptionReport, 'ZIC X7 10W-40');

        $this->assertNull($row['pack_contains']);
        $this->assertNull($row['remaining_packs_label']);
    }

    public function test_an_item_with_no_stock_level_shows_not_tracked_rather_than_zero(): void
    {
        $oil = $this->oil(['stock_level' => null]);
        $this->dispense($oil, '5.000');

        $row = $this->litreRow(new ConsumptionReport, 'ZIC X7 10W-40');

        $this->assertSame('5.000', $row['dispensed'], 'we still know what was poured');
        $this->assertFalse($row['stock_tracked']);
        $this->assertNull($row['remaining'], 'untracked is not the same as empty');
        $this->assertSame('not tracked', $row['remaining_label']);
        $this->assertNull($row['remaining_packs_label']);
    }

    public function test_negative_stock_is_shown_as_negative_and_flagged_for_a_recount(): void
    {
        $oil = $this->oil(['stock_level' => -4]);
        $this->dispense($oil, '32.000');

        $row = $this->litreRow(new ConsumptionReport, 'ZIC X7 10W-40');

        $this->assertSame('-4.000', $row['remaining']);
        $this->assertSame('-4.000 L', $row['remaining_label']);
        $this->assertTrue($row['is_negative'], 'oversold stock is the cue to recount the shelf');
    }

    public function test_an_item_at_or_below_its_threshold_is_flagged_as_low(): void
    {
        $this->oil(['stock_level' => 4, 'low_stock_alert' => 8]);
        $this->oil(['name' => 'Shell Helix HX7', 'stock_level' => 20, 'low_stock_alert' => 8]);

        $report = new ConsumptionReport;

        $this->assertTrue($this->litreRow($report, 'ZIC X7 10W-40')['is_low']);
        $this->assertFalse($this->litreRow($report, 'Shell Helix HX7')['is_low']);
    }

    public function test_an_item_never_dispensed_still_appears_with_its_stock(): void
    {
        $this->oil(['name' => 'Toyota Genuine 5W-30', 'stock_level' => 16]);

        $row = $this->litreRow(new ConsumptionReport, 'Toyota Genuine 5W-30');

        $this->assertNotSame([], $row, 'stock you own is still stock you own');
        $this->assertSame('0.000', $row['dispensed']);
        $this->assertSame(0, $row['sale_count']);
        $this->assertSame('16.000 L', $row['remaining_label']);
    }

    /* ---------------------------------------------------------------- */
    /* Piece items get their own section */
    /* ---------------------------------------------------------------- */

    public function test_piece_items_are_reported_in_their_own_section_counted_in_pieces(): void
    {
        $filter = Item::factory()->create([
            'name' => 'Oil Filter — Toyota Corolla',
            'unit_of_measure' => UnitOfMeasure::Piece,
            'stock_level' => 12,
            'low_stock_alert' => 4,
        ]);
        $this->oil();

        $this->sellPieces($filter, 3);
        $this->sellPieces($filter, 1);

        $report = new ConsumptionReport;

        $this->assertTrue($report->hasPieceItems());
        $row = $report->pieceRows()->firstWhere('item_name', 'Oil Filter — Toyota Corolla');

        $this->assertSame('4', $row['dispensed'], 'pieces are whole things, not litres');
        $this->assertSame('4', $row['dispensed_label']);
        $this->assertSame(2, $row['sale_count']);
        $this->assertSame('12', $row['remaining_label']);

        // And they are nowhere near the measured groups.
        $this->assertSame(['litre'], $report->measuredGroups()->keys()->all());
    }

    public function test_a_piece_item_is_never_folded_into_a_litre_total(): void
    {
        $oil = $this->oil();
        $filter = Item::factory()->create(['name' => 'Air Filter', 'unit_of_measure' => UnitOfMeasure::Piece, 'stock_level' => 9]);

        $this->dispense($oil, '4.000');
        $this->sellPieces($filter, 6);

        $this->assertSame(['litre' => '4.000'], (new ConsumptionReport)->totalsByUnit());
    }

    /* ---------------------------------------------------------------- */
    /* Empty state */
    /* ---------------------------------------------------------------- */

    public function test_the_report_reports_no_measured_items_when_none_are_configured(): void
    {
        Item::factory()->create(['name' => 'Wiper Blade Pair', 'unit_of_measure' => UnitOfMeasure::Piece]);

        $report = new ConsumptionReport;

        $this->assertFalse($report->hasMeasuredItems());
        $this->assertTrue($report->measuredGroups()->isEmpty());
    }

    public function test_the_empty_state_renders_when_no_measured_items_exist(): void
    {
        Item::factory()->create(['name' => 'Wiper Blade Pair', 'unit_of_measure' => UnitOfMeasure::Piece]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ConsumptionReportPage::class)
            ->assertSuccessful()
            ->assertSee('No items are set up in litres or kilograms yet');
    }

    /* ---------------------------------------------------------------- */
    /* The page */
    /* ---------------------------------------------------------------- */

    public function test_the_page_renders_litres_and_kilograms_without_summing_them(): void
    {
        $oil = $this->oil(['stock_level' => 28]);
        $gas = $this->gas(['stock_level' => 13]);

        $this->dispense($oil, '32.000');
        $this->dispense($gas, '13.000');

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ConsumptionReportPage::class)
            ->assertSuccessful()
            ->assertSee('ZIC X7 10W-40')
            ->assertSee('AC Gas Refill')
            ->assertSee('32.000 L')
            ->assertSee('13.000 kg')
            ->assertSee('1.75 Cartons')
            ->assertDontSee('45.000')
            ->assertDontSee('45 L');
    }

    public function test_the_page_shows_a_negative_balance_and_a_not_tracked_balance_honestly(): void
    {
        $oversold = $this->oil(['name' => 'Oversold Oil', 'stock_level' => -4]);
        $untracked = $this->oil(['name' => 'Untracked Oil', 'stock_level' => null]);

        $this->dispense($oversold, '1.000');
        $this->dispense($untracked, '2.000');

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ConsumptionReportPage::class)
            ->assertSuccessful()
            ->assertSee('-4.000 L')
            ->assertSee('not tracked')
            ->assertSee('Recount');
    }

    public function test_the_page_says_what_it_does_with_piece_items(): void
    {
        $this->oil();
        Item::factory()->create(['name' => 'Wiper Blade Pair', 'unit_of_measure' => UnitOfMeasure::Piece, 'stock_level' => 6]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ConsumptionReportPage::class)
            ->assertSuccessful()
            ->assertSee('Counted in pieces')
            ->assertSee('Wiper Blade Pair');
    }

    public function test_no_price_cost_or_total_appears_anywhere_on_the_page(): void
    {
        $oil = $this->oil(['unit_cost' => '4242.42']);
        $sale = Sale::factory()->create(['total_amount' => '9191.91', 'labor_charge' => '3131.31']);

        SaleItem::factory()->for($sale, 'sale')->create([
            'item_id' => $oil->id,
            'item_name' => $oil->name,
            'type' => SaleLineType::Product,
            'quantity' => 1,
            'dispensed_quantity' => '4.000',
            'manually_charged_price' => '8888.88',
        ]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ConsumptionReportPage::class)
            ->assertSuccessful()
            ->assertSee('4.000 L')
            ->assertDontSee('4242.42')
            ->assertDontSee('4,242.42')
            ->assertDontSee('8888.88')
            ->assertDontSee('8,888.88')
            ->assertDontSee('9191.91')
            ->assertDontSee('3131.31');
    }

    public function test_the_period_selector_narrows_the_window(): void
    {
        $oil = $this->oil();

        $old = Sale::factory()->create(['created_at' => now()->subDays(45)]);
        $this->dispense($oil, '12.000', $old);

        $recent = Sale::factory()->create(['created_at' => now()->subDay()]);
        $this->dispense($oil, '4.000', $recent);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ConsumptionReportPage::class)
            ->set('period', 'week')
            ->assertSee('4.000 L')
            ->assertDontSee('16.000 L')
            ->set('period', 'all')
            ->assertSee('16.000 L');
    }

    /* ---------------------------------------------------------------- */
    /* Access */
    /* ---------------------------------------------------------------- */

    public function test_an_admin_and_a_manager_may_open_the_consumption_report(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->assertTrue(ConsumptionReportPage::canAccess());

        $this->actingAs(User::factory()->manager()->create());
        $this->assertTrue(
            ConsumptionReportPage::canAccess(),
            'this is a stock screen, not a financial one — the counter reorders from it'
        );
    }

    public function test_a_technician_may_not_open_the_consumption_report(): void
    {
        $this->actingAs(User::factory()->technician()->create());

        $this->assertFalse(ConsumptionReportPage::canAccess());
    }

    public function test_a_guest_may_not_open_the_consumption_report(): void
    {
        $this->assertFalse(ConsumptionReportPage::canAccess());
    }

    public function test_the_page_stays_open_because_inventory_is_a_core_module(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $registry = app(ModuleRegistry::class);
        $registry->setEnabled('inventory', false);

        // Core modules ignore the switch entirely — setEnabled() is a no-op and
        // enabled() short-circuits to true. The gate is real, it just cannot
        // ever close for this module, and the test records that rather than a
        // guess about it.
        $this->assertTrue($registry->enabled('inventory'));
        $this->assertTrue(
            ConsumptionReportPage::canAccess(),
            'stock is core — the owner cannot switch away from knowing what is on the shelf'
        );
    }

    /* ---------------------------------------------------------------- */
    /* Demo data */
    /* ---------------------------------------------------------------- */

    public function test_the_seeder_produces_measured_items_with_sane_packaging(): void
    {
        $this->seed(DatabaseSeeder::class);

        $oil = Item::query()->where('name', 'like', 'ZIC X7%')->firstOrFail();

        $this->assertSame(UnitOfMeasure::Litre, $oil->unit_of_measure);
        $this->assertSame('Carton', $oil->pack_label);
        $this->assertSame(4, $oil->units_per_pack);
        $this->assertSame('16.000', $oil->packContains());
        $this->assertNotNull($oil->stock_level);

        $gas = Item::query()->where('name', 'AC Gas Refill')->firstOrFail();

        $this->assertSame(UnitOfMeasure::Kilogram, $gas->unit_of_measure);
        $this->assertSame(ItemType::Repair, $gas->type, 'a measured repair is legitimate');
        $this->assertSame('Cylinder', $gas->pack_label);
        $this->assertSame('13.000', $gas->packContains());
        $this->assertSame('13.000 kg', $gas->stockLabel(), 'a full cylinder on the shelf');

        $filter = Item::query()->where('name', 'like', 'Oil Filter%')->firstOrFail();

        $this->assertSame(UnitOfMeasure::Piece, $filter->unit_of_measure);
        $this->assertNotNull($filter->stock_level, 'the low-stock badge needs something to show');
        $this->assertNotNull($filter->low_stock_alert);
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = Item::query()->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, Item::query()->count());
    }

    public function test_the_seeded_catalogue_reports_both_litres_and_kilograms(): void
    {
        $this->seed(DatabaseSeeder::class);

        $report = new ConsumptionReport;

        $this->assertTrue($report->hasMeasuredItems());
        $this->assertSame(['litre', 'kilogram'], $report->measuredGroups()->keys()->all());
    }
}
