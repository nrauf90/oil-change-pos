<?php

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Models\Central\Shop;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\CashDrawer;
use App\Support\SaleTotalCalculator;
use App\Support\ShopTimezone;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CashDrawerTest extends TestCase
{
    use RefreshDatabase;

    /** Wednesday, mid-week and mid-month, so every boundary has room either side. */
    private const CLOCK = '2026-03-18 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::CLOCK));
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_cash_drawer_loads_with_an_empty_state_on_a_day_with_no_activity(): void
    {
        $response = $this->get(route('cash-drawer.index'));

        $response->assertOk()
            ->assertViewIs('cash-drawer.index')
            ->assertViewHas('hasActivity', false)
            ->assertSee('Nothing through the drawer');

        $this->assertSame('0.00', $response->viewData('cashIn'));
        $this->assertSame('0.00', $response->viewData('cashOut'));
        $this->assertSame('0.00', $response->viewData('net'));
        $this->assertSame(0, $response->viewData('saleCount'));
        $this->assertSame(0, $response->viewData('expenseCount'));
    }

    public function test_an_all_zero_day_never_divides_by_zero_in_a_percentage(): void
    {
        $this->assertSame([], $this->get(route('cash-drawer.index'))->viewData('breakdown'));

        // A logged outlay of zero still earns a category row, and its share of
        // a zero Cash Out has to be 0% rather than a division by zero.
        $this->expenseAt($this->clock()->setTime(9, 0), '0.00');

        $response = $this->get(route('cash-drawer.index'));

        $response->assertOk();
        $this->assertSame('0.00', $response->viewData('cashOut'));
        $this->assertSame('0.00', $response->viewData('net'));
        $this->assertSame(0.0, $response->viewData('breakdown')[0]['percent']);
    }

    public function test_cash_in_sums_only_sales_finalised_in_the_period(): void
    {
        $this->saleAt($this->clock()->setTime(9, 0), '1200.00');
        $this->saleAt($this->clock()->setTime(11, 30), '800.50');
        $this->saleAt($this->clock()->subDays(3), '5000.00');

        $response = $this->get(route('cash-drawer.index'));

        $this->assertSame('2000.50', $response->viewData('cashIn'));
        $this->assertSame(2, $response->viewData('saleCount'));
    }

    public function test_cash_out_sums_only_expenses_in_the_period(): void
    {
        $this->expenseAt($this->clock()->setTime(9, 15), '450.75');
        $this->expenseAt($this->clock()->setTime(16, 0), '120.25');
        $this->expenseAt($this->clock()->subDays(2), '9000.00');

        $response = $this->get(route('cash-drawer.index'));

        $this->assertSame('571.00', $response->viewData('cashOut'));
        $this->assertSame(2, $response->viewData('expenseCount'));
    }

    public function test_online_and_card_supplier_expenses_do_not_reduce_physical_drawer_cash(): void
    {
        Expense::factory()->create([
            'amount' => '500.00',
            'payment_method' => PaymentMethod::Cash,
            'spent_at' => $this->clock(),
        ]);
        Expense::factory()->create([
            'amount' => '1200.00',
            'payment_method' => PaymentMethod::Online,
            'spent_at' => $this->clock(),
        ]);
        Expense::factory()->create([
            'amount' => '800.00',
            'payment_method' => PaymentMethod::Card,
            'spent_at' => $this->clock(),
        ]);

        $response = $this->get(route('cash-drawer.index'));

        $this->assertSame('500.00', $response->viewData('cashOut'));
        $this->assertSame(1, $response->viewData('expenseCount'));
        $this->assertSame('2500.00', CashDrawer::sum(Expense::pluck('amount')));
    }

    public function test_net_cash_position_is_cash_in_minus_cash_out(): void
    {
        $this->saleAt($this->clock()->setTime(9, 0), '5000.00');
        $this->expenseAt($this->clock()->setTime(12, 0), '1750.25');

        $response = $this->get(route('cash-drawer.index'));

        $response->assertOk()->assertViewHas('hasActivity', true);

        $this->assertSame('5000.00', $response->viewData('cashIn'));
        $this->assertSame('1750.25', $response->viewData('cashOut'));
        $this->assertSame('3249.75', $response->viewData('net'));
        $this->assertFalse($response->viewData('isShort'));
        $response->assertSee('3,249.75');
    }

    public function test_a_day_of_expenses_with_no_sales_shows_a_negative_net_position(): void
    {
        $this->expenseAt($this->clock()->setTime(12, 0), '1250.50');

        $response = $this->get(route('cash-drawer.index'));

        $this->assertSame('0.00', $response->viewData('cashIn'));
        $this->assertSame('-1250.50', $response->viewData('net'));
        $this->assertTrue($response->viewData('isShort'));
        // The minus sign has to survive all the way to the counter screen.
        $response->assertSee('-1,250.50');
    }

    public function test_yesterdays_sale_and_expense_are_both_excluded_from_today(): void
    {
        $this->saleAt($this->clock()->subDay()->setTime(17, 0), '4000.00');
        $this->expenseAt($this->clock()->subDay()->setTime(18, 0), '600.00');

        $response = $this->get(route('cash-drawer.index'));

        $this->assertSame('0.00', $response->viewData('cashIn'));
        $this->assertSame('0.00', $response->viewData('cashOut'));
        $this->assertSame('0.00', $response->viewData('net'));
        $this->assertFalse($response->viewData('hasActivity'));
    }

    /**
     * Regression: the drawer closes on the shop's midnight, not the storage
     * timezone's. A Chicago shop reconciling at 20:00 local is already past
     * UTC midnight, so a UTC day boundary silently drops the whole morning.
     */
    public function test_the_drawer_reconciles_the_shops_own_day_not_the_storage_day(): void
    {
        $this->relocateShopTo('America/Chicago');

        $this->saleAt(Carbon::parse('2026-03-18 09:00', 'America/Chicago'), '1200.00');
        $this->saleAt(Carbon::parse('2026-03-18 19:30', 'America/Chicago'), '300.00');

        // 20:00 in Chicago is already 01:00 on the 19th in UTC.
        $this->travelTo(Carbon::parse('2026-03-18 20:00', 'America/Chicago'));

        $this->assertSame(
            '1500.00',
            $this->get(route('cash-drawer.index'))->viewData('cashIn'),
            "the shop's morning takings fell outside its own business day",
        );
    }

    public function test_a_sale_at_one_minute_to_midnight_still_counts_for_that_day(): void
    {
        $this->saleAt($this->clock()->startOfDay(), '100.00');
        $this->saleAt($this->clock()->endOfDay()->subMinute(), '250.00');

        $this->assertSame('350.00', $this->get(route('cash-drawer.index'))->viewData('cashIn'));
    }

    public function test_any_past_day_can_be_reconciled(): void
    {
        $this->saleAt($this->clock()->subDays(2)->setTime(10, 0), '3000.00');
        $this->expenseAt($this->clock()->subDays(2)->setTime(11, 0), '500.00');
        $this->saleAt($this->clock()->setTime(10, 0), '99999.00');

        $response = $this->get(route('cash-drawer.index', ['from' => '2026-03-16']));

        $this->assertSame('3000.00', $response->viewData('cashIn'));
        $this->assertSame('500.00', $response->viewData('cashOut'));
        $this->assertSame('2500.00', $response->viewData('net'));
    }

    public function test_a_date_range_can_be_reconciled_in_one_go(): void
    {
        $this->saleAt($this->clock()->subDays(3)->setTime(10, 0), '1000.00');
        $this->saleAt($this->clock()->subDays(2)->setTime(10, 0), '2000.00');
        $this->expenseAt($this->clock()->subDays(2)->setTime(11, 0), '250.00');
        $this->saleAt($this->clock()->setTime(10, 0), '7777.00');

        $response = $this->get(route('cash-drawer.index', ['from' => '2026-03-15', 'to' => '2026-03-16']));

        $this->assertSame('3000.00', $response->viewData('cashIn'));
        $this->assertSame('250.00', $response->viewData('cashOut'));
        $this->assertSame('2750.00', $response->viewData('net'));
        $this->assertSame(2, $response->viewData('saleCount'));
    }

    public function test_the_category_breakdown_adds_up_exactly_to_cash_out(): void
    {
        $this->expenseAt($this->clock()->setTime(9, 0), '450.75', ExpenseCategory::TeaLunch);
        $this->expenseAt($this->clock()->setTime(10, 0), '120.25', ExpenseCategory::TeaLunch);
        $this->expenseAt($this->clock()->setTime(11, 0), '3000.10', ExpenseCategory::PartsProcurement);
        $this->expenseAt($this->clock()->setTime(12, 0), '99.99', ExpenseCategory::ShopSupplies);

        $response = $this->get(route('cash-drawer.index'));
        $breakdown = collect($response->viewData('breakdown'));

        $this->assertSame(
            $response->viewData('cashOut'),
            SaleTotalCalculator::lineSubtotal($breakdown->pluck('amount')),
        );

        $tea = $breakdown->firstWhere('key', ExpenseCategory::TeaLunch->value);
        $this->assertSame('571.00', $tea['amount']);
        $this->assertSame(2, $tea['count']);

        // Only categories with money in them are listed, biggest first.
        $this->assertSame(
            [ExpenseCategory::PartsProcurement->value, ExpenseCategory::TeaLunch->value, ExpenseCategory::ShopSupplies->value],
            $breakdown->pluck('key')->all(),
        );
    }

    public function test_the_breakdown_percentages_are_a_share_of_cash_out(): void
    {
        $this->expenseAt($this->clock()->setTime(9, 0), '750.00', ExpenseCategory::Fuel);
        $this->expenseAt($this->clock()->setTime(10, 0), '250.00', ExpenseCategory::Rent);

        $breakdown = collect($this->get(route('cash-drawer.index'))->viewData('breakdown'));

        $this->assertSame(75.0, $breakdown->firstWhere('key', ExpenseCategory::Fuel->value)['percent']);
        $this->assertSame(25.0, $breakdown->firstWhere('key', ExpenseCategory::Rent->value)['percent']);
    }

    public function test_the_money_is_exact_and_never_drifts_on_awkward_decimals(): void
    {
        $this->saleAt($this->clock()->setTime(9, 0), '0.10');
        $this->saleAt($this->clock()->setTime(9, 5), '0.20');
        $this->expenseAt($this->clock()->setTime(10, 0), '0.10');
        $this->expenseAt($this->clock()->setTime(10, 5), '0.20');

        $response = $this->get(route('cash-drawer.index'));

        $this->assertSame('0.30', $response->viewData('cashIn'));
        $this->assertSame('0.30', $response->viewData('cashOut'));
        $this->assertSame('0.00', $response->viewData('net'));
    }

    public function test_a_lopsided_pile_of_paisa_still_nets_out_exactly(): void
    {
        foreach (['33.33', '33.33', '33.34'] as $price) {
            $this->saleAt($this->clock()->setTime(9, 0), $price);
        }

        $this->expenseAt($this->clock()->setTime(10, 0), '0.01');

        $response = $this->get(route('cash-drawer.index'));

        $this->assertSame('100.00', $response->viewData('cashIn'));
        $this->assertSame('99.99', $response->viewData('net'));
    }

    /* ---- Authorization ------------------------------------------------ */

    public function test_a_technician_cannot_see_the_cash_drawer(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->get(route('cash-drawer.index'))
            ->assertForbidden();
    }

    public function test_a_manager_can_view_the_cash_drawer(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(route('cash-drawer.index'))
            ->assertOk();
    }

    public function test_a_guest_is_redirected_to_login_from_the_cash_drawer(): void
    {
        auth()->logout();

        $this->get(route('cash-drawer.index'))->assertRedirect(route('login'));
    }

    public function test_the_cash_drawer_is_gone_when_the_module_is_switched_off(): void
    {
        app(ModuleRegistry::class)->setEnabled('expenses', false);

        $this->get(route('cash-drawer.index'))->assertNotFound();
    }

    /* ---- Helpers ------------------------------------------------------- */

    /**
     * The reconciliation clock, read in the shop's timezone.
     *
     * `startOfDay()` / `endOfDay()` off this instant are the shop's business
     * day — which is what a drawer reconciles. Parsing in the storage
     * timezone instead would assert a UTC day against a shop that does not
     * keep one.
     */
    /**
     * Move the shop to another timezone for the rest of the test.
     *
     * `TenantRuntimeState` snapshots the shop when the connection is
     * activated and `connect()` early-returns on a live lease, so the
     * connection has to be cycled for the new timezone to be seen.
     */
    private function relocateShopTo(string $timezone): void
    {
        $shop = app(TenantContext::class)->shop();
        $shop->timezone = $timezone;
        $shop->save();

        $manager = app(TenantConnectionManager::class);
        $manager->disconnect();
        $manager->connect(Shop::query()->findOrFail($shop->getKey()));

        // Cycling the connection drops the authenticated session with it.
        $this->actingAs(User::factory()->admin()->create());
    }

    private function clock(): Carbon
    {
        return Carbon::parse(self::CLOCK, ShopTimezone::current());
    }

    private function saleAt(Carbon $when, string $total): Sale
    {
        $resume = Carbon::getTestNow();
        $this->travelTo($when);

        $sale = Sale::factory()->create(['total_amount' => $total]);

        $this->travelTo($resume);

        return $sale;
    }

    private function expenseAt(Carbon $when, string $amount, ?ExpenseCategory $category = null): Expense
    {
        return Expense::factory()
            ->category($category ?? ExpenseCategory::ShopSupplies)
            ->amount($amount)
            ->spentAt($when)
            ->create();
    }
}
