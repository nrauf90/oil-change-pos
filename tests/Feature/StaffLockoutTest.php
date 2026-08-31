<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The shop must never be able to lock itself out of the back office.
 *
 * There is no route back in: an owner who unticks "Active" or picks "Manager"
 * on their own record fails canAccessPanel() on the very next request, and
 * recovery means `php artisan tinker` on the till.
 */
class StaffLockoutTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------- */
    /* Your own record */
    /* ---------------------------------------------------------------- */

    public function test_the_active_toggle_and_role_select_are_disabled_on_your_own_record(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->assertFormFieldDisabled('is_active')
            ->assertFormFieldDisabled('role');
    }

    public function test_the_same_fields_stay_editable_on_another_staff_record(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->manager()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $staff->getKey()])
            ->assertFormFieldEnabled('is_active')
            ->assertFormFieldEnabled('role');
    }

    public function test_the_sole_admin_cannot_deactivate_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasFormErrors(['is_active']);

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_the_sole_admin_cannot_demote_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['role' => Role::Manager->value])
            ->call('save')
            ->assertHasFormErrors(['role']);

        $this->assertTrue($admin->refresh()->isAdmin());
    }

    /* ---------------------------------------------------------------- */
    /* Disabling a field is not authorization: Livewire state is client-writable */
    /* ---------------------------------------------------------------- */

    public function test_a_crafted_livewire_request_cannot_demote_the_sole_admin(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->set('data.role', Role::Technician->value)
            ->call('save')
            ->assertHasFormErrors(['role']);

        $this->assertTrue($admin->refresh()->isAdmin());
    }

    public function test_a_crafted_livewire_request_cannot_deactivate_the_sole_admin(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->set('data.is_active', false)
            ->call('save')
            ->assertHasFormErrors(['is_active']);

        $this->assertTrue($admin->refresh()->is_active);
    }

    /* ---------------------------------------------------------------- */
    /* The rule is about the last admin, not about "is this me" */
    /* ---------------------------------------------------------------- */

    public function test_with_two_admins_one_of_them_can_be_demoted(): void
    {
        $keeper = User::factory()->admin()->create();
        $steppingDown = User::factory()->admin()->create();

        Livewire::actingAs($keeper)
            ->test(EditUser::class, ['record' => $steppingDown->getKey()])
            ->fillForm(['role' => Role::Manager->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($steppingDown->refresh()->isManager());
        $this->assertTrue($keeper->refresh()->isAdmin());
    }

    public function test_with_two_admins_one_of_them_can_be_deactivated(): void
    {
        $keeper = User::factory()->admin()->create();
        $leaving = User::factory()->admin()->create();

        Livewire::actingAs($keeper)
            ->test(EditUser::class, ['record' => $leaving->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($leaving->refresh()->is_active);
    }

    /**
     * users.update is data, not a constant: a shop that hands it to someone who
     * is not an admin must still not be able to erase its own last admin. So the
     * rule sits on the record being edited, never on "is this me".
     */
    public function test_the_last_admin_cannot_be_demoted_by_another_account_that_may_edit_staff(): void
    {
        $lastAdmin = User::factory()->admin()->create();

        $editor = User::factory()->manager()->create();
        $editor->givePermissionTo(Permission::ViewAnyUser->value, Permission::UpdateUser->value);

        Livewire::actingAs($editor)
            ->test(EditUser::class, ['record' => $lastAdmin->getKey()])
            ->fillForm(['role' => Role::Manager->value])
            ->call('save')
            ->assertHasFormErrors(['role']);

        $this->assertTrue($lastAdmin->refresh()->isAdmin());
    }

    public function test_the_last_admin_cannot_be_deactivated_by_another_account_that_may_edit_staff(): void
    {
        $lastAdmin = User::factory()->admin()->create();

        $editor = User::factory()->manager()->create();
        $editor->givePermissionTo(Permission::ViewAnyUser->value, Permission::UpdateUser->value);

        Livewire::actingAs($editor)
            ->test(EditUser::class, ['record' => $lastAdmin->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasFormErrors(['is_active']);

        $this->assertTrue($lastAdmin->refresh()->is_active);
    }

    /**
     * A second admin who has already been deactivated is not a way back in, so
     * they must not count towards "there is still an admin".
     */
    public function test_a_deactivated_admin_does_not_count_as_the_remaining_admin(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->inactive()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasFormErrors(['is_active']);

        $this->assertTrue($admin->refresh()->is_active);
    }

    /* ---------------------------------------------------------------- */
    /* Everything else still works normally */
    /* ---------------------------------------------------------------- */

    public function test_a_non_admin_staff_member_can_still_be_deactivated(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->manager()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($staff->refresh()->is_active);
    }

    public function test_the_sole_admin_can_still_edit_their_own_name(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['name' => 'Renamed Owner'])
            ->call('save')
            ->assertHasNoFormErrors();

        $admin->refresh();

        $this->assertSame('Renamed Owner', $admin->name);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->isAdmin());
    }
}
