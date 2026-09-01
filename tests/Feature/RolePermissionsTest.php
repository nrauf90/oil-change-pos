<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Pages\RolePermissions;
use App\Models\Permission as PermissionModel;
use App\Models\Role as RoleModel;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** The stored matrix for a role, straight from the database, order-insensitive. */
    private function storedPermissions(Role $role): array
    {
        $names = RoleModel::where('name', $role->value)
            ->sole()
            ->permissions
            ->pluck('name')
            ->all();

        sort($names);

        return $names;
    }

    /* ---------------------------------------------------------------- */
    /* The new permission itself */
    /* ---------------------------------------------------------------- */

    public function test_every_permission_in_the_enum_exists_in_the_database(): void
    {
        $stored = PermissionModel::pluck('name')->all();

        foreach (Permission::cases() as $permission) {
            $this->assertContains($permission->value, $stored, "{$permission->value} was never seeded");
        }
    }

    public function test_managing_roles_is_held_by_the_admin_alone(): void
    {
        $this->assertTrue($this->admin()->can(Permission::ManageRoles->value));
        $this->assertFalse(User::factory()->manager()->create()->can(Permission::ManageRoles->value));
        $this->assertFalse(User::factory()->technician()->create()->can(Permission::ManageRoles->value));
    }

    /**
     * Granting the module switchboard must not silently hand over the power to
     * rewrite the permission matrix — they are separate keys to the shop.
     */
    public function test_managing_roles_is_a_distinct_permission_from_managing_modules(): void
    {
        $this->assertNotSame(Permission::ManageModules->value, Permission::ManageRoles->value);
        $this->assertSame('roles.manage', Permission::ManageRoles->value);
    }

    /* ---------------------------------------------------------------- */
    /* Access */
    /* ---------------------------------------------------------------- */

    public function test_an_admin_can_open_the_role_permissions_screen(): void
    {
        $this->actingAs($this->admin());

        $this->assertTrue(RolePermissions::canAccess());

        // Over real HTTP too, not just as a Livewire component: the panel layout
        // and the route middleware are part of "can the owner open this".
        $this->get(RolePermissions::getUrl())->assertSuccessful();
    }

    public function test_a_manager_cannot_open_the_role_permissions_screen(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        $this->assertFalse(RolePermissions::canAccess());
    }

    public function test_a_technician_cannot_open_the_role_permissions_screen(): void
    {
        $this->actingAs(User::factory()->technician()->create());

        $this->assertFalse(RolePermissions::canAccess());
    }

    public function test_a_guest_cannot_reach_the_role_permissions_screen(): void
    {
        $this->get(RolePermissions::getUrl())->assertRedirect();
    }

    /* ---------------------------------------------------------------- */
    /* Rendering the matrix */
    /* ---------------------------------------------------------------- */

    public function test_the_screen_lists_every_permission_in_the_enum(): void
    {
        $page = Livewire::actingAs($this->admin())
            ->test(RolePermissions::class)
            ->assertSuccessful();

        foreach (Permission::cases() as $permission) {
            $page->assertSee($permission->value);
        }
    }

    public function test_the_screen_lists_every_role_and_every_permission_group(): void
    {
        $page = Livewire::actingAs($this->admin())
            ->test(RolePermissions::class);

        foreach (Role::cases() as $role) {
            $page->assertSee($role->label());
        }

        foreach (Permission::cases() as $permission) {
            $page->assertSee($permission->group());
        }
    }

    public function test_the_matrix_reflects_what_each_role_currently_holds(): void
    {
        $rows = collect(Livewire::actingAs($this->admin())
            ->test(RolePermissions::class)
            ->instance()
            ->getPermissionGroups())
            ->flatMap(fn (array $group) => $group['permissions'])
            ->keyBy('name');

        $this->assertCount(count(Permission::cases()), $rows, 'every permission must appear exactly once');

        $this->assertTrue($rows[Permission::UsePos->value]['roles'][Role::Manager->value]['held']);
        $this->assertFalse($rows[Permission::ViewMargins->value]['roles'][Role::Manager->value]['held']);
        $this->assertTrue($rows[Permission::ViewMargins->value]['roles'][Role::Admin->value]['held']);
        $this->assertFalse($rows[Permission::UsePos->value]['roles'][Role::Technician->value]['held']);
    }

    /** The admin column is decoration only — it can never be unticked. */
    public function test_the_admin_column_is_locked(): void
    {
        $rows = collect(Livewire::actingAs($this->admin())
            ->test(RolePermissions::class)
            ->instance()
            ->getPermissionGroups())
            ->flatMap(fn (array $group) => $group['permissions'])
            ->keyBy('name');

        foreach (Permission::cases() as $permission) {
            $this->assertTrue($rows[$permission->value]['roles'][Role::Admin->value]['held']);
            $this->assertTrue($rows[$permission->value]['roles'][Role::Admin->value]['locked']);
            $this->assertFalse($rows[$permission->value]['roles'][Role::Manager->value]['locked']);
        }
    }

    public function test_a_permission_belonging_to_a_disabled_module_is_flagged_but_still_listed(): void
    {
        app(ModuleRegistry::class)->setEnabled('scripts', false);

        $page = Livewire::actingAs($this->admin())->test(RolePermissions::class);

        $rows = collect($page->instance()->getPermissionGroups())
            ->flatMap(fn (array $group) => $group['permissions'])
            ->keyBy('name');

        $this->assertTrue($rows[Permission::ViewScripts->value]['moduleOff']);
        $this->assertFalse($rows[Permission::UsePos->value]['moduleOff']);

        $page->assertSee(Permission::ViewScripts->value)
            ->assertSee('Module off');
    }

    /* ---------------------------------------------------------------- */
    /* Editing */
    /* ---------------------------------------------------------------- */

    public function test_granting_a_permission_to_a_role_takes_effect_for_a_real_user(): void
    {
        $manager = User::factory()->manager()->create();

        // Warm spatie's permission cache first: a grant that only "works" because
        // nothing had been cached yet is not a working grant.
        $this->assertFalse($manager->can(Permission::ViewMargins->value));

        Livewire::actingAs($this->admin())
            ->test(RolePermissions::class)
            ->call('toggle', Role::Manager->value, Permission::ViewMargins->value);

        $this->assertContains(Permission::ViewMargins->value, $this->storedPermissions(Role::Manager));
        $this->assertTrue(User::find($manager->getKey())->can(Permission::ViewMargins->value));
    }

    public function test_revoking_a_permission_from_a_role_takes_effect_for_a_real_user(): void
    {
        $manager = User::factory()->manager()->create();

        $this->assertTrue($manager->can(Permission::UsePos->value));

        Livewire::actingAs($this->admin())
            ->test(RolePermissions::class)
            ->call('toggle', Role::Manager->value, Permission::UsePos->value);

        $this->assertNotContains(Permission::UsePos->value, $this->storedPermissions(Role::Manager));
        $this->assertFalse(User::find($manager->getKey())->can(Permission::UsePos->value));
    }

    /**
     * The lockout footgun: revoke `users.view_any` from the admin and nobody is
     * left who can put it back. The server refuses, not just the checkbox.
     */
    public function test_revoking_a_permission_from_the_admin_is_refused(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(RolePermissions::class)
            ->call('toggle', Role::Admin->value, Permission::ViewAnyUser->value);

        $this->assertContains(Permission::ViewAnyUser->value, $this->storedPermissions(Role::Admin));
        $this->assertTrue(User::find($admin->getKey())->can(Permission::ViewAnyUser->value));
    }

    public function test_the_admin_keeps_every_permission_after_toggling_each_one(): void
    {
        $page = Livewire::actingAs($this->admin())->test(RolePermissions::class);

        foreach (Permission::cases() as $permission) {
            $page->call('toggle', Role::Admin->value, $permission->value);
        }

        $this->assertSame(
            $this->sorted(Permission::values()),
            $this->storedPermissions(Role::Admin),
        );
    }

    public function test_an_unknown_role_or_permission_is_ignored(): void
    {
        $before = $this->storedPermissions(Role::Manager);

        Livewire::actingAs($this->admin())
            ->test(RolePermissions::class)
            ->call('toggle', 'wizard', Permission::UsePos->value)
            ->call('toggle', Role::Manager->value, 'sales.teleport');

        $this->assertSame($before, $this->storedPermissions(Role::Manager));
    }

    /**
     * A Livewire action is a public HTTP endpoint. Hiding the checkbox is not
     * authorization — mounting the page must be refused outright.
     */
    public function test_a_manager_cannot_invoke_the_toggle_through_livewire(): void
    {
        $manager = User::factory()->manager()->create();

        try {
            Livewire::actingAs($manager)
                ->test(RolePermissions::class)
                ->call('toggle', Role::Manager->value, Permission::ViewMargins->value);
            $this->fail('a manager reached the role permissions screen');
        } catch (\Throwable $e) {
            // Either the mount is refused or toggle() aborts — both are fine.
        }

        $this->assertNotContains(Permission::ViewMargins->value, $this->storedPermissions(Role::Manager));
        $this->assertFalse(User::find($manager->getKey())->can(Permission::ViewMargins->value));
    }

    public function test_a_technician_cannot_invoke_the_reset_through_livewire(): void
    {
        $technician = User::factory()->technician()->create();

        try {
            Livewire::actingAs($technician)
                ->test(RolePermissions::class)
                ->call('resetToDefaults', Role::Technician->value);
            $this->fail('a technician reached the role permissions screen');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertFalse(User::find($technician->getKey())->can(Permission::ManageRoles->value));
    }

    /* ---------------------------------------------------------------- */
    /* Reset to defaults */
    /* ---------------------------------------------------------------- */

    public function test_resetting_a_role_restores_exactly_the_shipped_matrix(): void
    {
        $page = Livewire::actingAs($this->admin())->test(RolePermissions::class);

        // Make a mess: take away something the manager should have, add two it should not.
        $page->call('toggle', Role::Manager->value, Permission::UsePos->value)
            ->call('toggle', Role::Manager->value, Permission::ViewMargins->value)
            ->call('toggle', Role::Manager->value, Permission::DeleteSale->value);

        $this->assertNotSame(
            $this->sorted(Role::Manager->permissionNames()),
            $this->storedPermissions(Role::Manager),
        );

        $page->call('resetToDefaults', Role::Manager->value);

        $this->assertSame(
            $this->sorted(Role::Manager->permissionNames()),
            $this->storedPermissions(Role::Manager),
        );
    }

    public function test_resetting_a_role_takes_effect_for_a_real_user(): void
    {
        $manager = User::factory()->manager()->create();

        $page = Livewire::actingAs($this->admin())->test(RolePermissions::class);

        $page->call('toggle', Role::Manager->value, Permission::UsePos->value);
        $this->assertFalse(User::find($manager->getKey())->can(Permission::UsePos->value));

        $page->call('resetToDefaults', Role::Manager->value);
        $this->assertTrue(User::find($manager->getKey())->can(Permission::UsePos->value));
    }

    public function test_resetting_the_admin_restores_every_permission(): void
    {
        Livewire::actingAs($this->admin())
            ->test(RolePermissions::class)
            ->call('resetToDefaults', Role::Admin->value);

        $this->assertSame($this->sorted(Permission::values()), $this->storedPermissions(Role::Admin));
    }

    /** @param  array<int, string>  $names */
    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }
}
