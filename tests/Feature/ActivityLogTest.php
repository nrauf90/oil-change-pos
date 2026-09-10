<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Filament\Pages\ModuleSwitchboard;
use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\Inspection;
use App\Models\Item;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------- */
    /* Helpers */
    /* ---------------------------------------------------------------- */

    /**
     * The one entry written for this action against this record.
     *
     * The trail now records ordinary work as well as deletions, so a test can
     * no longer reach for ActivityLog::sole() — it has to name what it means.
     */
    private function logFor(string $action, ?Model $subject = null): ActivityLog
    {
        $query = ActivityLog::query()->where('action', $action);

        if ($subject !== null) {
            $query->where('subject_type', $subject::class)->where('subject_id', $subject->getKey());
        }

        $entry = $query->latest('id')->first();

        $this->assertNotNull($entry, "no `{$action}` entry was written");

        return $entry;
    }

    /** @param array<string, mixed> $overrides */
    private function salePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Ali Raza',
            'phone' => '03001234567',
            'vehicle_model' => 'Toyota Corolla 2018',
            'vehicle_plate' => 'ABC-123',
            'mileage' => 84500,
            'labor_charge' => '',
            'misc_charge' => '',
            'lines' => [
                ['item_id' => null, 'item_name' => 'Custom work', 'type' => 'custom', 'manually_charged_price' => '1000'],
            ],
        ], $overrides);
    }

    /* ---------------------------------------------------------------- */
    /* Trade: every sale that closes is on the record */
    /* ---------------------------------------------------------------- */

    public function test_a_completed_sale_is_logged_with_its_invoice_number_and_total(): void
    {
        $cashier = User::factory()->manager()->create(['name' => 'Front Desk']);

        $this->actingAs($cashier)->post(route('sales.store'), $this->salePayload());

        $sale = Sale::latest('id')->firstOrFail();
        $entry = $this->logFor('sale.created', $sale);

        $this->assertStringContainsString($sale->invoice_number, $entry->description);
        $this->assertStringContainsString('1,000.00', $entry->description);
        $this->assertSame('1000.00', $entry->properties['total_amount']);
        $this->assertSame('Front Desk', $entry->user_name);
        $this->assertSame($cashier->id, $entry->user_id);
    }

    public function test_a_sale_entry_captures_the_discount_that_was_applied(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        $this->post(route('sales.store'), $this->salePayload(['discount' => '150']));

        $entry = $this->logFor('sale.created', Sale::latest('id')->firstOrFail());

        $this->assertSame('150.00', $entry->properties['discount']);
    }

    public function test_a_sale_entry_counts_the_lines_that_were_billed(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        $this->post(route('sales.store'), $this->salePayload([
            'lines' => [
                ['item_id' => null, 'item_name' => 'Oil', 'type' => 'custom', 'manually_charged_price' => '2000'],
                ['item_id' => null, 'item_name' => 'Filter', 'type' => 'custom', 'manually_charged_price' => '500'],
                ['item_id' => null, 'item_name' => 'Wash', 'type' => 'custom', 'manually_charged_price' => '300'],
            ],
        ]));

        $entry = $this->logFor('sale.created', Sale::latest('id')->firstOrFail());

        $this->assertSame(3, $entry->properties['line_count']);
    }

    public function test_a_rejected_checkout_leaves_nothing_on_the_record(): void
    {
        $before = ActivityLog::where('action', 'sale.created')->count();

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('sales.store'), $this->salePayload(['lines' => []]))
            ->assertSessionHasErrors();

        $this->assertSame($before, ActivityLog::where('action', 'sale.created')->count());
    }

    /* ---------------------------------------------------------------- */
    /* Destructive actions leave a trail */
    /* ---------------------------------------------------------------- */

    public function test_deleting_a_sale_is_recorded_with_who_did_it(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Shop Owner']);
        $sale = Sale::factory()->create(['total_amount' => 4200]);

        $this->actingAs($admin)->delete(route('sales.destroy', $sale));

        $entry = $this->logFor('sale.deleted', $sale);

        $this->assertSame($admin->id, $entry->user_id);
        $this->assertStringContainsString($sale->invoice_number, $entry->description);
    }

    public function test_the_owners_own_destructive_action_is_logged_against_their_name(): void
    {
        $owner = User::factory()->admin()->create(['name' => 'Shop Owner']);
        $sale = Sale::factory()->create();
        $item = Item::factory()->create(['name' => 'Owner Removed Oil']);

        $this->actingAs($owner)->delete(route('sales.destroy', $sale));
        $this->actingAs($owner)->delete(route('items.destroy', $item));

        // Nobody is exempt from the trail, least of all the person holding the
        // only account that can read it.
        $this->assertSame('Shop Owner', $this->logFor('sale.deleted', $sale)->user_name);
        $this->assertSame('Shop Owner', $this->logFor('item.deleted', $item)->user_name);
    }

    public function test_the_deleted_sale_total_is_kept_in_the_log(): void
    {
        $sale = Sale::factory()->create(['total_amount' => 4200, 'customer_name' => 'Ali Raza']);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('sales.destroy', $sale));

        $entry = $this->logFor('sale.deleted', $sale);

        $this->assertSame('4200.00', $entry->properties['total_amount'] ?? null);
        $this->assertSame('Ali Raza', $entry->properties['customer_name'] ?? null);
    }

    public function test_deleting_an_inventory_item_is_recorded(): void
    {
        $item = Item::factory()->create(['name' => 'ZIC 10W-40']);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('items.destroy', $item));

        $this->assertStringContainsString('ZIC 10W-40', $this->logFor('item.deleted', $item)->description);
    }

    public function test_deleting_an_expense_is_recorded(): void
    {
        $expense = Expense::factory()->create(['amount' => 350]);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('expenses.destroy', $expense));

        $this->logFor('expense.deleted', $expense);
    }

    public function test_a_refused_deletion_writes_no_log_entry(): void
    {
        $sale = Sale::factory()->create();

        $this->actingAs(User::factory()->manager()->create())
            ->delete(route('sales.destroy', $sale))
            ->assertForbidden();

        $this->assertDatabaseMissing('activity_logs', ['action' => 'sale.deleted'], 'tenant');
        $this->assertDatabaseHas('sales', ['id' => $sale->id], 'tenant');
    }

    /* ---------------------------------------------------------------- */
    /* Everyday inventory and cash work */
    /* ---------------------------------------------------------------- */

    public function test_adding_an_item_to_inventory_is_logged(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('items.store'), [
                'name' => 'Shell Helix 5W-30',
                'type' => 'product',
                'unit_cost' => '2400',
                'stock_level' => '12',
                'is_active' => 1,
            ])->assertRedirect();

        $item = Item::where('name', 'Shell Helix 5W-30')->sole();
        $entry = $this->logFor('item.created', $item);

        $this->assertStringContainsString('Shell Helix 5W-30', $entry->description);
    }

    public function test_editing_an_item_is_logged(): void
    {
        $item = Item::factory()->create(['name' => 'Old Name', 'type' => 'product']);

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('items.update', $item), [
                'name' => 'New Name',
                'type' => 'product',
                'is_active' => 1,
            ])->assertRedirect();

        $entry = $this->logFor('item.updated', $item);

        $this->assertArrayHasKey('name', $entry->properties['changed']);
        $this->assertSame('Old Name', $entry->properties['changed']['name']['from']);
        $this->assertSame('New Name', $entry->properties['changed']['name']['to']);
    }

    public function test_logging_an_expense_is_recorded(): void
    {
        $this->actingAs(User::factory()->manager()->create(['name' => 'Front Desk']))
            ->post(route('expenses.store'), [
                'category' => 'refreshments',
                'amount' => '450',
                'description' => 'Tea for the crew',
            ])->assertRedirect();

        $entry = $this->logFor('expense.created', Expense::latest('id')->firstOrFail());

        $this->assertSame('Front Desk', $entry->user_name);
        $this->assertStringContainsString('450.00', $entry->description);
    }

    public function test_editing_an_expense_is_recorded_as_a_diff(): void
    {
        $expense = Expense::factory()->create(['amount' => '100.00', 'description' => 'Tea']);

        $this->actingAs(User::factory()->admin()->create());
        $expense->update(['amount' => '900.00']);

        $entry = $this->logFor('expense.updated', $expense);

        $this->assertSame(['amount'], array_keys($entry->properties['changed']));
        $this->assertSame('900.00', $entry->properties['changed']['amount']['to']);
    }

    public function test_an_inspection_is_logged(): void
    {
        $technician = User::factory()->technician()->create(['name' => 'Bilal']);

        $this->actingAs($technician)->post(route('inspections.store'), [
            'customer_name' => 'Ali Raza',
            'vehicle_plate' => 'XYZ-999',
            'points' => [
                'engine_oil' => ['status' => 'ok', 'note' => ''],
            ],
        ])->assertRedirect();

        $entry = $this->logFor('inspection.created', Inspection::latest('id')->firstOrFail());

        $this->assertSame('Bilal', $entry->user_name);
        $this->assertStringContainsString('XYZ-999', $entry->description);
    }

    /* ---------------------------------------------------------------- */
    /* Staff accounts — and never their passwords */
    /* ---------------------------------------------------------------- */

    public function test_creating_a_staff_account_is_logged(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name' => 'Shop Owner']));

        $staff = User::create([
            'name' => 'New Cashier',
            'username' => 'newcashier',
            'password' => 'a-plain-text-secret',
            'is_active' => true,
        ]);

        $entry = $this->logFor('user.created', $staff);

        $this->assertSame('Shop Owner', $entry->user_name);
        $this->assertStringContainsString('New Cashier', $entry->description);
        $this->assertSame('newcashier', $entry->properties['username']);
    }

    public function test_updating_a_staff_account_is_logged(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $staff = User::factory()->manager()->create(['name' => 'Before', 'is_active' => true]);

        $staff->update(['name' => 'After']);

        $entry = $this->logFor('user.updated', $staff);

        $this->assertSame(['name'], array_keys($entry->properties['changed']));
        $this->assertSame('Before', $entry->properties['changed']['name']['from']);
        $this->assertSame('After', $entry->properties['changed']['name']['to']);
    }

    public function test_deleting_a_staff_account_is_logged(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name' => 'Shop Owner']));
        $staff = User::factory()->technician()->create(['name' => 'Departing Tech']);

        $staff->delete();

        $entry = $this->logFor('user.deleted');

        $this->assertSame('Shop Owner', $entry->user_name);
        $this->assertStringContainsString('Departing Tech', $entry->description);
    }

    public function test_no_password_or_hash_ever_reaches_the_log(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $staff = User::create([
            'name' => 'Secretive Sam',
            'username' => 'sam',
            'password' => 'first-plain-text-secret',
            'is_active' => true,
        ]);

        $staff->update(['name' => 'Sam Renamed', 'password' => 'second-plain-text-secret']);
        $staff->delete();

        $this->assertGreaterThanOrEqual(3, ActivityLog::count());

        foreach (ActivityLog::all() as $entry) {
            $blob = json_encode([$entry->description, $entry->properties], JSON_UNESCAPED_SLASHES);

            $this->assertStringNotContainsString('first-plain-text-secret', $blob);
            $this->assertStringNotContainsString('second-plain-text-secret', $blob);
            $this->assertDoesNotMatchRegularExpression(
                '/\$2[aby]\$\d{2}\$/',
                $blob,
                'a bcrypt hash reached the audit trail'
            );

            $properties = (array) $entry->properties;
            $this->assertArrayNotHasKey('password', $properties);
            $this->assertArrayNotHasKey('password', (array) ($properties['changed'] ?? []));
        }
    }

    public function test_a_password_change_is_recorded_as_a_fact_not_a_value(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $staff = User::factory()->manager()->create();

        $staff->update(['password' => 'a-brand-new-secret']);

        $entry = $this->logFor('user.updated', $staff);

        $this->assertTrue($entry->properties['password_changed'] ?? false);
    }

    public function test_a_routine_sign_in_does_not_fill_the_log(): void
    {
        $staff = User::factory()->manager()->create();

        // The login controller stamps last_login_at on every sign-in. Bookkeeping
        // like that is not an administrative act and must not drown the trail.
        $staff->forceFill(['last_login_at' => now()])->save();

        $this->assertDatabaseMissing('activity_logs', ['action' => 'user.updated'], 'tenant');
    }

    public function test_an_update_that_moves_only_the_timestamp_is_not_logged(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $item = Item::factory()->create(['name' => 'Unchanged Oil']);

        // A bare touch writes a new updated_at and nothing else. Churn is not
        // news, and an audit trail full of it is one nobody reads.
        $this->travel(5)->minutes();
        $item->touch();

        $this->assertDatabaseMissing('activity_logs', ['action' => 'item.updated'], 'tenant');
    }

    /* ---------------------------------------------------------------- */
    /* The log survives the actor */
    /* ---------------------------------------------------------------- */

    public function test_removing_a_member_of_staff_does_not_erase_their_trail(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Departed Owner']);
        $sale = Sale::factory()->create();

        $this->actingAs($admin)->delete(route('sales.destroy', $sale));

        $entry = $this->logFor('sale.deleted', $sale);
        $admin->delete();
        $entry->refresh();

        $this->assertNull($entry->user_id, 'the foreign key nulls out');
        $this->assertSame('Departed Owner', $entry->user_name, 'but the name snapshot survives');
    }

    public function test_an_owner_removing_their_own_account_is_still_recorded(): void
    {
        $owner = User::factory()->admin()->create(['name' => 'Self Removing Owner']);

        $this->actingAs($owner);
        $owner->delete();

        $entry = $this->logFor('user.deleted');

        // The link has nowhere to point once the row is gone; the name is what
        // the trail is really for.
        $this->assertNull($entry->user_id);
        $this->assertSame('Self Removing Owner', $entry->user_name);
    }

    /* ---------------------------------------------------------------- */
    /* Append-only: not over HTTP, and not from code either */
    /* ---------------------------------------------------------------- */

    public function test_a_log_entry_cannot_be_deleted_from_code(): void
    {
        $entry = ActivityLog::factory()->bySystem()->create(['description' => 'Indelible entry']);

        $this->assertThrows(fn () => ActivityLog::query()->firstOrFail()->delete(), LogicException::class);

        $this->assertDatabaseHas('activity_logs', ['id' => $entry->id, 'description' => 'Indelible entry'], 'tenant');
    }

    public function test_a_log_entry_cannot_be_modified(): void
    {
        $entry = ActivityLog::factory()->bySystem()->create(['description' => 'Original wording']);

        $this->assertThrows(fn () => $entry->update(['description' => 'Tampered wording']), LogicException::class);

        $this->assertDatabaseHas('activity_logs', ['id' => $entry->id, 'description' => 'Original wording'], 'tenant');
        $this->assertDatabaseMissing('activity_logs', ['description' => 'Tampered wording'], 'tenant');
    }

    public function test_the_activity_log_is_append_only_over_http(): void
    {
        $this->assertFalse(
            collect(app('router')->getRoutes())->contains(
                fn ($route) => str_starts_with((string) $route->getName(), 'activity-log.')
                    && array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])
            ),
            'an audit trail that can be edited or deleted through the app is not an audit trail'
        );
    }

    /* ---------------------------------------------------------------- */
    /* Reading the log */
    /* ---------------------------------------------------------------- */

    public function test_an_admin_can_read_the_activity_log(): void
    {
        $sale = Sale::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->delete(route('sales.destroy', $sale));

        $this->get(route('activity-log.index'))
            ->assertOk()
            ->assertSee($sale->invoice_number);
    }

    public function test_the_activity_log_has_an_empty_state(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // Creating that account is itself an entry now, so the only way to reach
        // a genuinely empty trail is the one the model refuses to offer: going
        // around it, straight at the table. That is the point — the log empties
        // when the database is wiped, and by no other means.
        DB::connection('tenant')->table('activity_logs')->delete();

        $this->get(route('activity-log.index'))
            ->assertOk()
            ->assertSee('Nothing logged yet');
    }

    public function test_a_manager_cannot_read_the_activity_log(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get(route('activity-log.index'))
            ->assertForbidden();
    }

    public function test_a_technician_cannot_read_the_activity_log(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->get(route('activity-log.index'))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_read_the_activity_log(): void
    {
        $this->get(route('activity-log.index'))->assertRedirect(route('login'));
    }

    public function test_the_log_is_newest_first(): void
    {
        $admin = User::factory()->admin()->create();
        $first = Sale::factory()->create();
        $second = Sale::factory()->create();

        $this->actingAs($admin)->delete(route('sales.destroy', $first));
        $this->actingAs($admin)->delete(route('sales.destroy', $second));

        $this->get(route('activity-log.index', ['action' => 'sale.deleted']))
            ->assertOk()
            ->assertSeeInOrder([$second->invoice_number, $first->invoice_number]);
    }

    public function test_the_log_can_be_filtered_by_action(): void
    {
        $admin = User::factory()->admin()->create();
        $sale = Sale::factory()->create();
        $item = Item::factory()->create(['name' => 'Filterable Oil']);

        $this->actingAs($admin)->delete(route('sales.destroy', $sale));
        $this->actingAs($admin)->delete(route('items.destroy', $item));

        $this->get(route('activity-log.index', ['action' => 'item.deleted']))
            ->assertOk()
            ->assertSee('Filterable Oil')
            ->assertDontSee($sale->invoice_number);
    }

    public function test_the_log_can_be_filtered_by_date_range(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        ActivityLog::factory()->bySystem()->create([
            'description' => 'Something from last month',
            'created_at' => now()->subDays(40),
        ]);
        ActivityLog::factory()->bySystem()->create([
            'description' => 'Something from yesterday',
            'created_at' => now()->subDay(),
        ]);

        $this->get(route('activity-log.index', ['from' => now()->subDays(7)->toDateString()]))
            ->assertOk()
            ->assertSee('Something from yesterday')
            ->assertDontSee('Something from last month');

        $this->get(route('activity-log.index', ['to' => now()->subDays(7)->toDateString()]))
            ->assertOk()
            ->assertSee('Something from last month')
            ->assertDontSee('Something from yesterday');
    }

    public function test_the_log_can_be_searched_by_description(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        ActivityLog::factory()->bySystem()->create(['description' => 'Removed Castrol GTX from inventory']);
        ActivityLog::factory()->bySystem()->create(['description' => 'Deleted a refreshments outlay']);

        $this->get(route('activity-log.index', ['q' => 'castrol']))
            ->assertOk()
            ->assertSee('Removed Castrol GTX from inventory')
            ->assertDontSee('refreshments outlay');
    }

    public function test_the_filters_combine_rather_than_replace_one_another(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        ActivityLog::factory()->bySystem()->action('item.deleted')
            ->create(['description' => 'Removed Castrol GTX from inventory']);
        ActivityLog::factory()->bySystem()->action('sale.deleted')
            ->create(['description' => 'Deleted a Castrol-heavy invoice']);

        $this->get(route('activity-log.index', ['q' => 'Castrol', 'action' => 'item.deleted']))
            ->assertOk()
            ->assertSee('Removed Castrol GTX from inventory')
            ->assertDontSee('Castrol-heavy invoice');
    }

    public function test_the_log_paginates(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        ActivityLog::factory()->bySystem()->count(30)
            ->sequence(fn ($sequence) => ['description' => 'Entry number '.($sequence->index + 1)])
            ->create();

        $this->get(route('activity-log.index'))
            ->assertOk()
            ->assertSee('Entry number 30')
            ->assertDontSee('Entry number 5');

        // 25 to a page, newest first, so the oldest five are one click away.
        $this->get(route('activity-log.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Entry number 5')
            ->assertDontSee('Entry number 30');
    }

    public function test_the_changed_values_are_shown_on_screen(): void
    {
        $admin = User::factory()->admin()->create();
        $item = Item::factory()->create(['name' => 'Before Rename']);

        $this->actingAs($admin);
        $item->update(['name' => 'After Rename']);

        $this->get(route('activity-log.index', ['action' => 'item.updated']))
            ->assertOk()
            ->assertSee('Before Rename')
            ->assertSee('After Rename');
    }

    /* ---------------------------------------------------------------- */
    /* The log is a record, not a workspace */
    /* ---------------------------------------------------------------- */

    public function test_reading_the_log_does_not_hand_over_the_module_switchboard(): void
    {
        $auditor = User::factory()->create();
        $auditor->givePermissionTo(Permission::ViewActivityLog->value);

        $this->actingAs($auditor);

        $this->assertTrue($auditor->can(Permission::ViewActivityLog->value));
        $this->assertFalse(
            ModuleSwitchboard::canAccess(),
            'letting somebody read the audit trail must not let them switch the shop\'s features off'
        );
    }
}
