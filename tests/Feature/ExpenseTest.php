<?php

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExpenseTest extends TestCase
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

    public function test_the_expense_list_loads_with_an_empty_state(): void
    {
        $this->get(route('expenses.index'))
            ->assertOk()
            ->assertViewIs('expenses.index')
            ->assertSee('No expenses logged');
    }

    public function test_the_expense_form_loads(): void
    {
        $this->get(route('expenses.create'))
            ->assertOk()
            ->assertViewIs('expenses.create')
            ->assertSee('Shop Supplies');
    }

    public function test_an_expense_is_logged_with_category_amount_and_description(): void
    {
        $this->post(route('expenses.store'), [
            'category' => 'tea_lunch',
            'amount' => '450.75',
            'description' => 'Lunch for the two mechanics',
        ])->assertRedirect(route('expenses.index'));

        $this->assertDatabaseHas('expenses', [
            'category' => 'tea_lunch',
            'amount' => '450.75',
            'description' => 'Lunch for the two mechanics',
        ]);
    }

    public function test_logged_by_is_the_signed_in_user_and_a_forged_user_id_is_ignored(): void
    {
        $imposter = User::factory()->manager()->create();
        $me = User::factory()->admin()->create();

        $this->actingAs($me)->post(route('expenses.store'), [
            'category' => 'fuel',
            'amount' => '3000',
            'user_id' => $imposter->id,
        ])->assertRedirect(route('expenses.index'));

        $this->assertDatabaseHas('expenses', ['category' => 'fuel', 'user_id' => $me->id]);
        $this->assertDatabaseMissing('expenses', ['user_id' => $imposter->id]);
    }

    public function test_amount_is_required(): void
    {
        $this->post(route('expenses.store'), ['category' => 'utility'])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_amount_must_be_numeric(): void
    {
        $this->post(route('expenses.store'), ['category' => 'utility', 'amount' => 'five hundred'])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_a_negative_amount_is_rejected(): void
    {
        $this->post(route('expenses.store'), ['category' => 'utility', 'amount' => '-100'])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_the_category_must_be_a_real_enum_case(): void
    {
        $this->post(route('expenses.store'), ['category' => 'bribes', 'amount' => '100'])
            ->assertSessionHasErrors('category');

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_the_category_is_required(): void
    {
        $this->post(route('expenses.store'), ['amount' => '100'])
            ->assertSessionHasErrors('category');

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_an_expense_defaults_to_now_when_no_date_is_given(): void
    {
        $this->post(route('expenses.store'), ['category' => 'utility', 'amount' => '100']);

        $this->assertTrue(
            Expense::sole()->spent_at->equalTo(Carbon::parse(self::CLOCK)),
            'spent_at should default to the current moment.'
        );
    }

    public function test_an_expense_can_be_back_dated(): void
    {
        $this->post(route('expenses.store'), [
            'category' => 'rent',
            'amount' => '25000',
            'spent_at' => '2026-03-01 09:15',
        ])->assertRedirect(route('expenses.index'));

        $this->assertSame('2026-03-01 09:15:00', Expense::sole()->spent_at->toDateTimeString());
    }

    public function test_an_expense_can_be_updated(): void
    {
        $expense = Expense::factory()->category(ExpenseCategory::Utility)->amount('100.00')->create();

        $this->get(route('expenses.edit', $expense))->assertOk()->assertViewIs('expenses.edit');

        $this->put(route('expenses.update', $expense), [
            'category' => 'shop_supplies',
            'amount' => '275.25',
            'description' => 'Rags and hand cleaner',
        ])->assertRedirect(route('expenses.index'));

        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'category' => 'shop_supplies',
            'amount' => '275.25',
            'description' => 'Rags and hand cleaner',
        ]);
    }

    public function test_updating_an_expense_never_reassigns_who_logged_it(): void
    {
        $owner = User::factory()->manager()->create();
        $imposter = User::factory()->manager()->create();
        $expense = Expense::factory()->for($owner)->create();

        $this->put(route('expenses.update', $expense), [
            'category' => 'fuel',
            'amount' => '500',
            'user_id' => $imposter->id,
        ])->assertRedirect(route('expenses.index'));

        $this->assertSame($owner->id, $expense->fresh()->user_id);
    }

    public function test_an_expense_can_be_deleted(): void
    {
        $expense = Expense::factory()->create();

        $this->delete(route('expenses.destroy', $expense))
            ->assertRedirect(route('expenses.index'));

        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    public function test_the_list_shows_logged_expenses_with_their_category_and_who_logged_them(): void
    {
        $user = User::factory()->manager()->create(['name' => 'Rashid Counter']);
        Expense::factory()->for($user)->category(ExpenseCategory::TeaLunch)
            ->create(['description' => 'Chai for the crew', 'amount' => '320.00']);

        $this->get(route('expenses.index'))
            ->assertOk()
            ->assertSee('Chai for the crew')
            ->assertSee('Tea / Lunch')
            ->assertSee('Rashid Counter')
            ->assertSee('320.00');
    }

    public function test_the_list_filters_by_date_range_and_totals_only_the_matches(): void
    {
        Expense::factory()->amount('100.00')->spentAt('2026-03-10 09:00')->create(['description' => 'Too early']);
        Expense::factory()->amount('250.50')->spentAt('2026-03-15 09:00')->create(['description' => 'In range one']);
        Expense::factory()->amount('49.50')->spentAt('2026-03-17 23:30')->create(['description' => 'In range two']);
        Expense::factory()->amount('900.00')->spentAt('2026-03-18 09:00')->create(['description' => 'Too late']);

        $response = $this->get(route('expenses.index', ['from' => '2026-03-15', 'to' => '2026-03-17']));

        $response->assertOk()
            ->assertSee('In range one')
            ->assertSee('In range two')
            ->assertDontSee('Too early')
            ->assertDontSee('Too late');

        $this->assertSame('300.00', $response->viewData('filteredTotal'));
        $this->assertSame(2, $response->viewData('filteredCount'));
    }

    public function test_the_list_filters_by_category_and_totals_only_that_category(): void
    {
        Expense::factory()->category(ExpenseCategory::Fuel)->amount('1000.00')->create(['description' => 'Diesel run']);
        Expense::factory()->category(ExpenseCategory::Fuel)->amount('250.25')->create(['description' => 'Petrol top-up']);
        Expense::factory()->category(ExpenseCategory::Rent)->amount('25000.00')->create(['description' => 'March rent']);

        $response = $this->get(route('expenses.index', ['category' => 'fuel']));

        $response->assertOk()
            ->assertSee('Diesel run')
            ->assertSee('Petrol top-up')
            ->assertDontSee('March rent');

        $this->assertSame('1250.25', $response->viewData('filteredTotal'));
    }

    public function test_a_nonsense_category_filter_is_ignored_rather_than_hiding_everything(): void
    {
        Expense::factory()->amount('100.00')->create(['description' => 'Still visible']);

        $response = $this->get(route('expenses.index', ['category' => 'bribes']));

        $response->assertOk()->assertSee('Still visible');
        $this->assertSame('100.00', $response->viewData('filteredTotal'));
    }

    public function test_the_running_total_is_exact_with_awkward_decimals(): void
    {
        Expense::factory()->amount('0.10')->create();
        Expense::factory()->amount('0.20')->create();

        $this->assertSame('0.30', $this->get(route('expenses.index'))->viewData('filteredTotal'));
    }

    /* ---- Authorization ------------------------------------------------ */

    public function test_a_technician_cannot_see_the_expense_list(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->get(route('expenses.index'))
            ->assertForbidden();
    }

    public function test_a_technician_cannot_open_or_post_the_expense_form(): void
    {
        $technician = User::factory()->technician()->create();

        $this->actingAs($technician)->get(route('expenses.create'))->assertForbidden();
        $this->actingAs($technician)->post(route('expenses.store'), [
            'category' => 'fuel', 'amount' => '100',
        ])->assertForbidden();

        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_a_manager_can_log_an_expense(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post(route('expenses.store'), [
            'category' => 'shop_supplies', 'amount' => '640.00',
        ])->assertRedirect(route('expenses.index'));

        $this->assertDatabaseHas('expenses', ['user_id' => $manager->id, 'amount' => '640.00']);
    }

    public function test_a_manager_can_update_an_expense_but_not_delete_one(): void
    {
        $manager = User::factory()->manager()->create();
        $expense = Expense::factory()->create();

        $this->actingAs($manager)->put(route('expenses.update', $expense), [
            'category' => 'utility', 'amount' => '80',
        ])->assertRedirect(route('expenses.index'));

        $this->actingAs($manager)->delete(route('expenses.destroy', $expense))->assertForbidden();

        $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
    }

    public function test_an_admin_can_delete_an_expense(): void
    {
        $expense = Expense::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('expenses.destroy', $expense))
            ->assertRedirect(route('expenses.index'));

        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    public function test_a_guest_is_redirected_to_login_from_every_expense_route(): void
    {
        $expense = Expense::factory()->create();

        auth()->logout();

        $this->get(route('expenses.index'))->assertRedirect(route('login'));
        $this->get(route('expenses.create'))->assertRedirect(route('login'));
        $this->post(route('expenses.store'), [])->assertRedirect(route('login'));
        $this->get(route('expenses.edit', $expense))->assertRedirect(route('login'));
        $this->put(route('expenses.update', $expense), [])->assertRedirect(route('login'));
        $this->delete(route('expenses.destroy', $expense))->assertRedirect(route('login'));
    }

    public function test_every_expense_route_is_gone_when_the_module_is_switched_off(): void
    {
        $expense = Expense::factory()->create();

        app(ModuleRegistry::class)->setEnabled('expenses', false);

        $this->get(route('expenses.index'))->assertNotFound();
        $this->get(route('expenses.create'))->assertNotFound();
        $this->post(route('expenses.store'), [])->assertNotFound();
        $this->get(route('expenses.edit', $expense))->assertNotFound();
        $this->put(route('expenses.update', $expense), [])->assertNotFound();
        $this->delete(route('expenses.destroy', $expense))->assertNotFound();
    }
}
