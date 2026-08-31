<?php

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\Item;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing that destroys a record goes on the first tap.
 *
 * These screens are used on a tablet propped up in a workshop by someone with
 * oil on their hands, and each of these buttons erases something for good: an
 * invoice out of the day's takings, a part out of the catalogue, a receipt out
 * of the drawer reconciliation. The counter already knows the gesture from
 * "Clear" on the sale ticket — arm it, then confirm it — so every destructive
 * button in the app uses the same one.
 */
class DestructiveActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    /** The armed state is what the second tap confirms; without it there is only one tap. */
    public function test_deleting_an_invoice_has_to_be_armed_first(): void
    {
        Sale::factory()->create();

        $this->get(route('sales.index'))
            ->assertOk()
            ->assertSee('armed: false', false)
            ->assertSee('Confirm?', false);
    }

    public function test_deleting_an_inventory_item_has_to_be_armed_first(): void
    {
        Item::factory()->create();

        $this->get(route('items.index'))
            ->assertOk()
            ->assertSee('armed: false', false)
            ->assertSee('Confirm?', false);
    }

    public function test_deleting_an_expense_has_to_be_armed_first(): void
    {
        Expense::factory()->create(['category' => ExpenseCategory::cases()[0]]);

        $this->get(route('expenses.index'))
            ->assertOk()
            ->assertSee('armed: false', false)
            ->assertSee('Confirm?', false);
    }

    /**
     * A row of identical "Delete"s tells a screen reader nothing about which
     * record it is on, so each button names the thing it destroys.
     */
    public function test_a_delete_button_names_what_it_will_destroy(): void
    {
        $sale = Sale::factory()->create();

        $this->get(route('sales.index'))
            ->assertOk()
            ->assertSee($sale->invoice_number);
    }

    /**
     * Arming is a courtesy on the way in, not the authorisation. The route still
     * carries the permission, and the delete itself still goes through.
     */
    public function test_arming_does_not_stand_in_for_the_permission_check(): void
    {
        $sale = Sale::factory()->create();

        $this->actingAs(User::factory()->technician()->create())
            ->delete(route('sales.destroy', $sale))
            ->assertForbidden();

        $this->assertDatabaseHas('sales', ['id' => $sale->id]);
    }

    public function test_a_confirmed_delete_still_removes_the_record(): void
    {
        $sale = Sale::factory()->create();

        $this->delete(route('sales.destroy', $sale))->assertRedirect(route('sales.index'));

        $this->assertDatabaseMissing('sales', ['id' => $sale->id]);
    }
}
