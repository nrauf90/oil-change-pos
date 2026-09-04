<?php

namespace Tests\Feature;

use App\Enums\SaleLineType;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\SaleTotalCalculator;
use App\Support\ShopTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    /** Wednesday, mid-week and mid-month, so every boundary has room either side. */
    private const CLOCK = '2026-03-18 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());

        $this->travelTo(Carbon::parse(self::CLOCK));
    }

    public function test_the_dashboard_loads_with_an_empty_state_when_there_are_no_sales(): void
    {
        $response = $this->get(route('reports.index'));

        $response->assertOk();
        $response->assertViewIs('reports.index');
        $response->assertViewHas('hasSales', false);
        $response->assertSee('No sales recorded yet');
    }

    public function test_todays_card_sums_only_sales_created_today(): void
    {
        $this->saleAt($this->clock()->setTime(9, 0), [['price' => '1200.00']]);
        $this->saleAt($this->clock()->setTime(9, 30), [['price' => '800.50']]);
        $this->saleAt($this->clock()->subDays(3), [['price' => '5000.00']]);

        $headline = $this->get(route('reports.index'))->viewData('headline');

        $this->assertSame('2000.50', $headline['today']['revenue']);
        $this->assertSame(2, $headline['today']['count']);
    }

    public function test_yesterdays_sale_is_excluded_from_today_but_counted_in_the_month(): void
    {
        $this->saleAt($this->clock()->subDay(), [['price' => '1500.00']]);

        $headline = $this->get(route('reports.index'))->viewData('headline');

        $this->assertSame('0.00', $headline['today']['revenue']);
        $this->assertSame(0, $headline['today']['count']);
        $this->assertSame('1500.00', $headline['month']['revenue']);
        $this->assertSame(1, $headline['month']['count']);
    }

    public function test_the_weekly_card_covers_this_week_and_excludes_last_week(): void
    {
        // Monday of this week, and the Sunday that closed the previous one.
        $this->saleAt($this->clock()->startOfWeek(), [['price' => '600.00']]);
        $this->saleAt($this->clock()->startOfWeek()->subSecond(), [['price' => '999.00']]);

        $headline = $this->get(route('reports.index'))->viewData('headline');

        $this->assertSame('600.00', $headline['week']['revenue']);
        $this->assertSame(1, $headline['week']['count']);
    }

    public function test_the_monthly_card_covers_this_month_and_excludes_last_month(): void
    {
        $this->saleAt($this->clock()->startOfMonth(), [['price' => '2500.00']]);
        $this->saleAt($this->clock()->startOfMonth()->subSecond(), [['price' => '7777.00']]);

        $headline = $this->get(route('reports.index'))->viewData('headline');

        $this->assertSame('2500.00', $headline['month']['revenue']);
        $this->assertSame(1, $headline['month']['count']);
    }

    public function test_revenue_uses_the_manually_charged_price_and_never_the_item_unit_cost(): void
    {
        $item = Item::factory()->create(['name' => 'ZIC X7 10W-40', 'unit_cost' => 9999]);

        $this->saleAt($this->clock(), [[
            'item_id' => $item->id,
            'name' => 'ZIC X7 10W-40',
            'price' => '100.00',
        ]]);

        $response = $this->get(route('reports.index'));

        $response->assertOk();
        $this->assertSame('100.00', $response->viewData('headline')['today']['revenue']);
        $response->assertSee('100.00');
        $response->assertDontSee('9999');
        $response->assertDontSee('9,999');
    }

    public function test_labor_and_misc_charges_are_included_in_reported_revenue(): void
    {
        $this->saleAt($this->clock(), [['price' => '1000.00']], labor: '450.00', misc: '50.00');

        $headline = $this->get(route('reports.index'))->viewData('headline');

        $this->assertSame('1500.00', $headline['today']['revenue']);
    }

    public function test_the_daily_breakdown_is_a_continuous_series_including_zero_sale_days(): void
    {
        $this->saleAt($this->clock(), [['price' => '300.00']]);
        $this->saleAt($this->clock()->subDays(2), [['price' => '700.00']]);

        $breakdown = $this->get(route('reports.index'))->viewData('breakdown');

        $this->assertCount(14, $breakdown);

        $byKey = collect($breakdown)->keyBy('key');
        $this->assertSame('300.00', $byKey['2026-03-18']['revenue']);
        $this->assertSame('700.00', $byKey['2026-03-16']['revenue']);
        $this->assertSame('0.00', $byKey['2026-03-17']['revenue'], 'a quiet day must still appear');
        $this->assertSame(0, $byKey['2026-03-17']['count']);
        $this->assertSame('2026-03-05', $breakdown[0]['key'], 'the series starts 14 days back');
        $this->assertSame('2026-03-18', end($breakdown)['key'], 'the series ends today');
    }

    public function test_period_weekly_switches_the_breakdown_to_weeks(): void
    {
        $this->saleAt($this->clock()->subWeeks(2), [['price' => '250.00']]);

        $response = $this->get(route('reports.index', ['period' => 'weekly']));

        $response->assertViewHas('period', 'weekly');
        $breakdown = $response->viewData('breakdown');

        $this->assertCount(12, $breakdown);
        $this->assertSame('250.00', collect($breakdown)->keyBy('key')['2026-03-02']['revenue']);
        $this->assertSame('2026-03-16', end($breakdown)['key'], 'the series ends on the current week');
    }

    public function test_period_monthly_switches_the_breakdown_to_months(): void
    {
        $this->saleAt($this->clock()->subMonths(3), [['price' => '4000.00']]);

        $response = $this->get(route('reports.index', ['period' => 'monthly']));

        $response->assertViewHas('period', 'monthly');
        $breakdown = $response->viewData('breakdown');

        $this->assertCount(12, $breakdown);
        $this->assertSame('2026-03', end($breakdown)['key']);
        $this->assertSame('4000.00', collect($breakdown)->keyBy('key')['2025-12']['revenue']);
    }

    public function test_an_invalid_period_falls_back_to_the_daily_default(): void
    {
        $response = $this->get(route('reports.index', ['period' => 'nonsense']));

        $response->assertOk();
        $response->assertViewHas('period', 'daily');
        $this->assertCount(14, $response->viewData('breakdown'));
    }

    public function test_the_revenue_split_adds_up_to_the_window_revenue(): void
    {
        $this->saleAt($this->clock(), [
            ['name' => 'Shell Helix', 'type' => SaleLineType::Product, 'price' => '3200.00'],
            ['name' => 'Brake bleed', 'type' => SaleLineType::Repair, 'price' => '1800.00'],
            ['name' => 'Door latch fix', 'type' => SaleLineType::Custom, 'price' => '650.00'],
        ], labor: '500.00', misc: '75.50');

        $response = $this->get(route('reports.index'));
        $split = collect($response->viewData('split'))->keyBy('key');

        $this->assertSame('3200.00', $split['product']['amount']);
        $this->assertSame('1800.00', $split['repair']['amount']);
        $this->assertSame('650.00', $split['custom']['amount']);
        $this->assertSame('500.00', $split['labor']['amount']);
        $this->assertSame('75.50', $split['misc']['amount']);

        $this->assertSame('6225.50', $response->viewData('windowRevenue'));
        $this->assertSame(
            $response->viewData('windowRevenue'),
            SaleTotalCalculator::lineSubtotal($split->pluck('amount')),
            'the split must reconcile exactly with the window revenue'
        );
    }

    public function test_top_items_lists_the_most_frequently_charged_item_names(): void
    {
        $this->saleAt($this->clock(), [
            ['name' => 'Oil filter', 'price' => '450.00'],
            ['name' => 'Air filter', 'price' => '900.00'],
        ]);
        $this->saleAt($this->clock()->subDay(), [['name' => 'Oil filter', 'price' => '500.00']]);
        $this->saleAt($this->clock()->subDays(2), [['name' => 'Oil filter', 'price' => '550.00']]);

        $topItems = $this->get(route('reports.index'))->viewData('topItems');

        $this->assertSame('Oil filter', $topItems[0]['item_name']);
        $this->assertSame(3, $topItems[0]['times_charged']);
        $this->assertSame('1500.00', $topItems[0]['revenue']);
        $this->assertSame('Air filter', $topItems[1]['item_name']);
        $this->assertSame(1, $topItems[1]['times_charged']);
    }

    public function test_the_sale_count_counts_sales_not_lines(): void
    {
        $this->saleAt($this->clock(), [
            ['price' => '100.00'],
            ['price' => '100.00'],
            ['price' => '100.00'],
        ]);

        $response = $this->get(route('reports.index'));

        $this->assertSame(1, $response->viewData('headline')['today']['count']);
        $this->assertSame(1, collect($response->viewData('breakdown'))->keyBy('key')['2026-03-18']['count']);
    }

    public function test_money_is_rendered_with_two_decimals_and_thousands_separators(): void
    {
        $this->saleAt($this->clock(), [['price' => '12345.60']]);

        $response = $this->get(route('reports.index'));

        $response->assertOk();
        $response->assertSee('12,345.60');
    }

    public function test_a_sale_with_no_lines_still_reports_its_labor_and_misc_revenue(): void
    {
        $this->saleAt($this->clock(), [], labor: '1200.00', misc: '0.00');

        $response = $this->get(route('reports.index'));
        $split = collect($response->viewData('split'))->keyBy('key');

        $this->assertSame('1200.00', $response->viewData('windowRevenue'));
        $this->assertSame('0.00', $split['product']['amount']);
        $this->assertSame('1200.00', $split['labor']['amount']);
    }

    public function test_the_split_and_top_items_follow_the_selected_period_window(): void
    {
        $this->saleAt($this->clock()->subMonths(6), [['name' => 'Gearbox flush', 'price' => '3000.00']]);

        $daily = $this->get(route('reports.index'));
        $this->assertSame('0.00', $daily->viewData('windowRevenue'));
        $this->assertSame([], $daily->viewData('topItems'));

        $monthly = $this->get(route('reports.index', ['period' => 'monthly']));
        $this->assertSame('3000.00', $monthly->viewData('windowRevenue'));
        $this->assertSame('Gearbox flush', $monthly->viewData('topItems')[0]['item_name']);
    }

    public function test_top_items_are_capped_at_ten_rows(): void
    {
        $lines = [];

        for ($i = 1; $i <= 12; $i++) {
            $lines[] = ['name' => 'Part '.$i, 'price' => '100.00'];
        }

        $this->saleAt($this->clock(), $lines);

        $this->assertCount(10, $this->get(route('reports.index'))->viewData('topItems'));
    }

    public function test_the_dashboard_pulls_in_no_external_scripts(): void
    {
        $this->saleAt($this->clock(), [['price' => '100.00']]);

        $html = $this->get(route('reports.index'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '~<script[^>]+src=["\']https?://~i',
            (string) $html,
            'the workshop machine is offline — the chart must be pure CSS'
        );
    }

    /**
     * Build a sale (and its lines) as if the counter had rung it up at $when.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function saleAt(Carbon $when, array $lines = [], string $labor = '0.00', string $misc = '0.00'): Sale
    {
        $resume = Carbon::getTestNow();
        $this->travelTo($when);

        $sale = Sale::factory()->create([
            'labor_charge' => $labor,
            'misc_charge' => $misc,
            'total_amount' => SaleTotalCalculator::total(array_column($lines, 'price'), $labor, $misc),
        ]);

        foreach ($lines as $line) {
            SaleItem::factory()->for($sale, 'sale')->create([
                'item_id' => $line['item_id'] ?? null,
                'item_name' => $line['name'] ?? 'Engine oil',
                'type' => $line['type'] ?? SaleLineType::Product,
                'manually_charged_price' => $line['price'],
            ]);
        }

        $this->travelTo($resume);

        return $sale;
    }

    private function clock(): Carbon
    {
        // Read in the shop's timezone: the headline cards bound the shop's
        // day / week / month, so startOfWeek() here must mean the shop's week.
        return Carbon::parse(self::CLOCK, ShopTimezone::current());
    }
}
