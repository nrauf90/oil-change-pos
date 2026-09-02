<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Item;
use App\Models\Permission as PermissionModel;
use App\Models\Role as RoleModel;
use App\Models\Sale;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------- */
    /* The permission catalogue itself */
    /* ---------------------------------------------------------------- */

    public function test_every_permission_in_the_enum_exists_in_the_database(): void
    {
        $stored = PermissionModel::pluck('name')->all();

        foreach (Permission::cases() as $permission) {
            $this->assertContains($permission->value, $stored, "{$permission->value} was never seeded");
        }
    }

    public function test_the_database_holds_no_permission_the_enum_does_not_declare(): void
    {
        $this->assertSame(
            [],
            array_diff(PermissionModel::pluck('name')->all(), Permission::values()),
            'a stray permission row means the enum is no longer the single source of truth'
        );
    }

    public function test_all_three_roles_are_seeded(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertNotNull(RoleModel::where('name', $role->value)->first());
        }
    }

    public function test_every_permission_is_reachable_by_at_least_one_role(): void
    {
        $granted = collect(Role::cases())->flatMap->permissionNames()->unique();

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                $granted->contains($permission->value),
                "{$permission->value} is granted to nobody — it is dead"
            );
        }
    }

    public function test_the_admin_holds_every_permission(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (Permission::cases() as $permission) {
            $this->assertTrue($admin->can($permission->value), "admin is missing {$permission->value}");
        }
    }

    /* ---------------------------------------------------------------- */
    /* The role matrix */
    /* ---------------------------------------------------------------- */

    public function test_a_manager_can_run_the_counter_but_cannot_delete_or_see_margins(): void
    {
        $manager = User::factory()->manager()->create();

        foreach (['pos.use', 'sales.create', 'sales.view_any', 'items.quick_create', 'expenses.create'] as $allowed) {
            $this->assertTrue($manager->can($allowed), "manager should hold {$allowed}");
        }

        foreach ([
            'sales.delete', 'items.delete', 'expenses.delete',
            'items.view_unit_cost', 'items.set_unit_cost', 'reports.view_margins',
            'users.view_any', 'users.create', 'logs.view',
        ] as $denied) {
            $this->assertFalse($manager->can($denied), "manager must NOT hold {$denied}");
        }
    }

    public function test_a_technician_holds_only_workshop_floor_permissions(): void
    {
        $technician = User::factory()->technician()->create();

        foreach (['scripts.view', 'service_history.lookup', 'inspections.create', 'inspections.view_any'] as $allowed) {
            $this->assertTrue($technician->can($allowed), "technician should hold {$allowed}");
        }

        foreach ([
            'pos.use', 'sales.create', 'sales.view_any', 'sales.view', 'pricing.view',
            'expenses.view_any', 'expenses.view_cash_drawer',
            'reports.view_dashboard', 'reports.view_financials',
            'items.view_any', 'users.view_any',
        ] as $denied) {
            $this->assertFalse($technician->can($denied), "technician must NOT hold {$denied}");
        }
    }

    public function test_a_user_holds_exactly_one_role(): void
    {
        $user = User::factory()->manager()->create();
        $user->assignRoleEnum(Role::Technician);

        $this->assertCount(1, $user->refresh()->roles);
        $this->assertTrue($user->isTechnician());
        $this->assertFalse($user->isManager());
    }

    /* ---------------------------------------------------------------- */
    /* Route enforcement — the matrix that actually protects data */
    /* ---------------------------------------------------------------- */

    public function test_a_technician_cannot_reach_the_counter_screen(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->get(route('pos.create'))
            ->assertForbidden();
    }

    public function test_a_technician_cannot_record_a_sale(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->post(route('sales.store'), [
                'lines' => [['item_id' => null, 'item_name' => 'X', 'type' => 'custom', 'manually_charged_price' => '100']],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    public function test_a_technician_cannot_read_sales_history_or_an_invoice(): void
    {
        $sale = Sale::factory()->create();

        $this->actingAs(User::factory()->technician()->create());

        $this->get(route('sales.index'))->assertForbidden();
        $this->get(route('sales.show', $sale))->assertForbidden();
        $this->get(route('sales.pdf', $sale))->assertForbidden();
    }

    public function test_a_technician_cannot_reach_the_reporting_dashboard(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->get(route('reports.index'))
            ->assertForbidden();
    }

    public function test_a_technician_cannot_reach_inventory(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->get(route('items.index'))
            ->assertForbidden();
    }

    public function test_a_technician_can_read_the_counter_scripts(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->get(route('scripts.index'))
            ->assertOk();
    }

    public function test_a_manager_cannot_delete_a_sale(): void
    {
        $sale = Sale::factory()->create();

        $this->actingAs(User::factory()->manager()->create())
            ->delete(route('sales.destroy', $sale))
            ->assertForbidden();

        $this->assertDatabaseHas('sales', ['id' => $sale->id], 'tenant');
    }

    public function test_an_admin_can_delete_a_sale(): void
    {
        $sale = Sale::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('sales.destroy', $sale))
            ->assertRedirect(route('sales.index'));

        $this->assertDatabaseMissing('sales', ['id' => $sale->id], 'tenant');
    }

    public function test_a_manager_cannot_delete_an_inventory_item(): void
    {
        $item = Item::factory()->create();

        $this->actingAs(User::factory()->manager()->create())
            ->delete(route('items.destroy', $item))
            ->assertForbidden();

        $this->assertDatabaseHas('items', ['id' => $item->id], 'tenant');
    }

    public function test_a_manager_can_create_an_item_on_the_fly(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->postJson(route('quick-items.store'), ['name' => 'Shell Helix 5W-30', 'type' => 'product'])
            ->assertCreated();
    }

    public function test_a_technician_cannot_create_an_item_on_the_fly(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->postJson(route('quick-items.store'), ['name' => 'Sneaky Item', 'type' => 'product'])
            ->assertForbidden();

        $this->assertDatabaseCount('items', 0, 'tenant');
    }

    /* ---------------------------------------------------------------- */
    /* Deactivated staff */
    /* ---------------------------------------------------------------- */

    public function test_a_deactivated_admin_cannot_reach_the_back_office_panel(): void
    {
        $user = User::factory()->admin()->inactive()->create();

        $this->assertFalse($user->canAccessPanel(app(Panel::class)));
    }

    public function test_a_technician_cannot_reach_the_back_office_panel(): void
    {
        $this->assertFalse(
            User::factory()->technician()->create()->canAccessPanel(app(Panel::class))
        );
    }

    public function test_an_admin_can_reach_the_back_office_panel(): void
    {
        $this->assertTrue(
            User::factory()->admin()->create()->canAccessPanel(app(Panel::class))
        );
    }

    /* ---------------------------------------------------------------- */
    /* Guests */
    /* ---------------------------------------------------------------- */

    public function test_every_named_application_route_rejects_a_guest(): void
    {
        $sale = Sale::factory()->create();
        $item = Item::factory()->create();

        $gets = [
            route('pos.create'),
            route('sales.index'),
            route('sales.show', $sale),
            route('sales.pdf', $sale),
            route('items.index'),
            route('items.create'),
            route('items.edit', $item),
            route('reports.index'),
            route('scripts.index'),
            route('quick-items.index'),
        ];

        foreach ($gets as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_a_guest_cannot_post_a_sale(): void
    {
        $this->post(route('sales.store'), [
            'lines' => [['item_id' => null, 'item_name' => 'X', 'type' => 'custom', 'manually_charged_price' => '100']],
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    /* ---------------------------------------------------------------- */
    /* Module gating layered on top of permissions */
    /* ---------------------------------------------------------------- */

    public function test_a_disabled_module_blocks_even_an_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        app(ModuleRegistry::class)->setEnabled('scripts', false);

        $this->get(route('scripts.index'))->assertNotFound();
    }
}
