<?php

namespace Tests\Feature;

use App\Enums\DashboardPeriod;
use App\Filament\Widgets\MonthlyFinancialChart;
use App\Filament\Widgets\TodayFinancialStats;
use App\Models\Expense;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\AdminDashboardMetrics;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardWidgetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_admin_dashboard_shows_todays_financial_summary(): void
    {
        $this->travelTo('2026-03-18 14:00:00');
        $admin = User::factory()->admin()->create();
        $costedItem = Item::factory()->create(['unit_cost' => '600.00']);
        $sale = Sale::factory()->create([
            'total_amount' => '1500.00',
            'created_at' => now(),
        ]);
        SaleItem::factory()->create([
            'sale_id' => $sale,
            'item_id' => $costedItem,
            'quantity' => 1,
            'manually_charged_price' => '1000.00',
        ]);
        SaleItem::factory()->custom()->create([
            'sale_id' => $sale,
            'quantity' => 1,
            'manually_charged_price' => '500.00',
        ]);
        Expense::factory()->create(['amount' => '300.00', 'spent_at' => now()]);
        Sale::factory()->create(['total_amount' => '9000.00', 'created_at' => now()->subDay()]);

        Livewire::actingAs($admin)
            ->test(TodayFinancialStats::class)
            ->assertSee('Today sales')
            ->assertSee('1,500.00')
            ->assertSee('Yesterday: 9,000.00 | Difference: -7,500.00 (-83.3%)')
            ->assertSee('Today expenses')
            ->assertSee('300.00')
            ->assertSee('Today gross margin')
            ->assertSee('400.00')
            ->assertSee('excludes uncosted sales');
    }

    public function test_period_filter_changes_the_overview_to_week_and_compares_last_week(): void
    {
        $this->travelTo('2026-03-18 14:00:00');
        $admin = User::factory()->admin()->create();
        Sale::factory()->create(['total_amount' => '1200.00', 'created_at' => '2026-03-17 12:00:00']);
        Sale::factory()->create(['total_amount' => '800.00', 'created_at' => '2026-03-10 12:00:00']);

        Livewire::actingAs($admin)
            ->test(TodayFinancialStats::class, ['pageFilters' => ['period' => DashboardPeriod::Week->value]])
            ->assertSee('This week overview')
            ->assertSee('This week sales')
            ->assertSee('1,200.00')
            ->assertSee('Last week: 800.00 | Difference: +400.00 (+50.0%)');
    }

    public function test_period_filter_changes_chart_to_month_and_includes_previous_month_series(): void
    {
        $this->travelTo('2026-03-18 14:00:00');
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(MonthlyFinancialChart::class, ['pageFilters' => ['period' => DashboardPeriod::Month->value]])
            ->assertSee('This month compared with last month')
            ->assertSee('This month sales')
            ->assertSee('Last month Sales');
    }

    public function test_monthly_metrics_cover_twelve_months_and_use_recorded_costs(): void
    {
        $this->travelTo('2026-03-18 14:00:00');
        $item = Item::factory()->create(['unit_cost' => '200.00']);
        $sale = Sale::factory()->create([
            'total_amount' => '1000.00',
            'created_at' => '2025-12-10 12:00:00',
        ]);
        SaleItem::factory()->create([
            'sale_id' => $sale,
            'item_id' => $item,
            'quantity' => 2,
            'manually_charged_price' => '1000.00',
        ]);
        Expense::factory()->create([
            'amount' => '250.00',
            'spent_at' => '2025-12-11 12:00:00',
        ]);

        $months = (new AdminDashboardMetrics)->monthly();
        $december = collect($months)->firstWhere('label', 'Dec 2025');

        $this->assertCount(12, $months);
        $this->assertSame('Apr 2025', $months[0]['label']);
        $this->assertSame('Mar 2026', $months[11]['label']);
        $this->assertSame([
            'label' => 'Dec 2025',
            'sales' => '1000.00',
            'expenses' => '250.00',
            'margin' => '600.00',
        ], $december);
    }

    public function test_financial_widgets_are_available_only_to_staff_allowed_to_view_margins(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($admin);
        $this->assertTrue(TodayFinancialStats::canView());
        $this->assertTrue(MonthlyFinancialChart::canView());

        $this->actingAs($manager);
        $this->assertFalse(TodayFinancialStats::canView());
        $this->assertFalse(MonthlyFinancialChart::canView());
    }
}
