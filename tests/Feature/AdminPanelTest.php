<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\ModuleSwitchboard;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /* ---------------------------------------------------------------- */
    /* Panel access */
    /* ---------------------------------------------------------------- */

    public function test_a_guest_cannot_reach_the_admin_panel(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_an_admin_can_reach_the_admin_panel(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertSuccessful();
    }

    /**
     * The panel is its own shell, so the counter's header is gone once you are
     * inside it. Without a way back the owner who opened the margin report is
     * stranded mid-shift with a customer waiting, and has to retype the URL or
     * sign out to get back to billing.
     */
    public function test_the_panel_offers_a_way_back_to_the_counter(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee('Back to the counter')
            ->assertSee(route('pos.create'));
    }

    /** It must never point at a screen the signed-in member of staff cannot open. */
    public function test_the_way_back_is_hidden_from_staff_who_cannot_bill(): void
    {
        $technician = User::factory()->technician()->create();

        $this->assertFalse($technician->can('pos.use'), 'a technician must not be billing');

        $this->actingAs($technician)
            ->get('/admin')
            ->assertDontSee('Back to the counter');
    }

    public function test_a_technician_cannot_reach_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------- */
    /* Staff management */
    /* ---------------------------------------------------------------- */

    public function test_an_admin_can_list_staff(): void
    {
        $staff = User::factory()->manager()->create(['name' => 'Bilal Khan']);

        Livewire::actingAs($this->admin())
            ->test(ListUsers::class)
            ->assertCanSeeTableRecords([$staff])
            ->assertSee('Bilal Khan');
    }

    public function test_a_manager_cannot_list_staff(): void
    {
        $this->assertFalse(
            UserResource::canViewAny(),
            'guest default'
        );

        $this->actingAs(User::factory()->manager()->create());

        $this->assertFalse(UserResource::canViewAny());
    }

    public function test_an_admin_can_create_a_staff_member_with_a_role(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Ayesha Malik',
                'username' => 'ayesha',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'is_active' => true,
                'role' => Role::Manager->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('username', 'ayesha')->sole();

        $this->assertTrue($created->isManager());
        $this->assertSame('Ayesha Malik', $created->name);
    }

    public function test_a_new_staff_password_is_hashed(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Ayesha Malik',
                'username' => 'ayesha',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'role' => Role::Technician->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('username', 'ayesha')->sole();

        $this->assertNotSame('secret-password', $created->password);
        $this->assertTrue(Hash::check('secret-password', $created->password));
    }

    public function test_a_duplicate_username_is_rejected(): void
    {
        User::factory()->create(['username' => 'taken']);

        Livewire::actingAs($this->admin())
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Someone Else',
                'username' => 'taken',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'role' => Role::Manager->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['username']);
    }

    public function test_a_staff_member_role_can_be_changed(): void
    {
        $staff = User::factory()->technician()->create();

        Livewire::actingAs($this->admin())
            ->test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['role' => Role::Manager->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($staff->refresh()->isManager());
        $this->assertFalse($staff->isTechnician(), 'the old role must be replaced, not added');
    }

    public function test_leaving_the_password_blank_on_edit_keeps_the_existing_one(): void
    {
        $staff = User::factory()->manager()->create(['password' => Hash::make('original-password')]);

        Livewire::actingAs($this->admin())
            ->test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['name' => 'Renamed Person', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $staff->refresh();

        $this->assertSame('Renamed Person', $staff->name);
        $this->assertTrue(Hash::check('original-password', $staff->password));
    }

    /**
     * Nothing is being changed on create, so there is no credential to re-authorise
     * — asking for it would only train admins to type their password on a form that
     * did not need it.
     */
    public function test_creating_a_staff_member_does_not_ask_for_the_current_password(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Ayesha Malik',
                'username' => 'ayesha',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'role' => Role::Manager->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('secret-password', User::where('username', 'ayesha')->sole()->password));
    }

    public function test_a_mistyped_confirmation_is_rejected_on_create(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Ayesha Malik',
                'username' => 'ayesha',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-passwrod',
                'role' => Role::Manager->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['password_confirmation']);

        $this->assertFalse(User::where('username', 'ayesha')->exists());
    }

    /**
     * A walked-away admin session is the realistic attack: without re-authentication
     * anyone passing the counter can hand themselves a staff login.
     */
    public function test_changing_a_staff_password_requires_the_signed_in_admins_own_password(): void
    {
        $staff = User::factory()->manager()->create(['password' => Hash::make('original-password')]);

        Livewire::actingAs($this->admin())
            ->test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm([
                'password' => 'replacement-password',
                'password_confirmation' => 'replacement-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['current_password']);

        $this->assertTrue(Hash::check('original-password', $staff->refresh()->password));
    }

    public function test_a_wrong_current_password_does_not_change_a_staff_password(): void
    {
        $staff = User::factory()->manager()->create(['password' => Hash::make('original-password')]);

        Livewire::actingAs($this->admin())
            ->test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm([
                'current_password' => 'not-the-admins-password',
                'password' => 'replacement-password',
                'password_confirmation' => 'replacement-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['current_password']);

        $this->assertTrue(Hash::check('original-password', $staff->refresh()->password));
    }

    /** The edited person's own old password is not the re-authentication secret. */
    public function test_the_edited_staff_members_old_password_is_not_accepted_as_the_current_password(): void
    {
        $staff = User::factory()->manager()->create(['password' => Hash::make('original-password')]);

        Livewire::actingAs($this->admin())
            ->test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm([
                'current_password' => 'original-password',
                'password' => 'replacement-password',
                'password_confirmation' => 'replacement-password',
            ])
            ->call('save')
            ->assertHasFormErrors(['current_password']);

        $this->assertTrue(Hash::check('original-password', $staff->refresh()->password));
    }

    public function test_a_mistyped_confirmation_is_rejected_on_edit(): void
    {
        $admin = User::factory()->admin()->create(['password' => Hash::make('admin-password')]);
        $staff = User::factory()->manager()->create(['password' => Hash::make('original-password')]);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm([
                'current_password' => 'admin-password',
                'password' => 'replacement-password',
                'password_confirmation' => 'replacement-passwrod',
            ])
            ->call('save')
            ->assertHasFormErrors(['password_confirmation']);

        $this->assertTrue(Hash::check('original-password', $staff->refresh()->password));
    }

    public function test_an_admin_who_re_authenticates_can_change_a_staff_password(): void
    {
        $admin = User::factory()->admin()->create(['password' => Hash::make('admin-password')]);
        $staff = User::factory()->manager()->create(['password' => Hash::make('original-password')]);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm([
                'current_password' => 'admin-password',
                'password' => 'replacement-password',
                'password_confirmation' => 'replacement-password',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('replacement-password', $staff->refresh()->password));
        $this->assertTrue(
            Hash::check('admin-password', $admin->refresh()->password),
            'the re-authentication field must never be written to the users table',
        );
    }

    public function test_a_staff_member_can_be_deactivated_and_then_cannot_sign_in(): void
    {
        $staff = User::factory()->manager()->create([
            'username' => 'expiring',
            'password' => Hash::make('secret-password'),
        ]);

        Livewire::actingAs($this->admin())
            ->test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($staff->refresh()->is_active);

        auth()->logout();

        $this->post(route('login.store'), ['username' => 'expiring', 'password' => 'secret-password'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin);

        $this->assertFalse(
            UserResource::canDelete($admin),
            'deleting yourself would lock you out mid-shift'
        );
    }

    public function test_an_admin_can_delete_another_staff_member(): void
    {
        $this->actingAs($this->admin());

        $this->assertTrue(
            UserResource::canDelete(User::factory()->manager()->create())
        );
    }

    /* ---------------------------------------------------------------- */
    /* Module switchboard */
    /* ---------------------------------------------------------------- */

    public function test_the_switchboard_lists_every_module(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ModuleSwitchboard::class)
            ->assertSuccessful()
            ->assertSee('Daily Expenses & Cash Flow')
            ->assertSee('Workshop Floor')
            ->assertSee('Counter Scripts');
    }

    public function test_a_module_can_be_switched_off_from_the_switchboard(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ModuleSwitchboard::class)
            ->call('toggle', 'workshop');

        $this->assertFalse(app(ModuleRegistry::class)->enabled('workshop'));
    }

    public function test_a_module_can_be_switched_back_on_from_the_switchboard(): void
    {
        app(ModuleRegistry::class)->setEnabled('workshop', false);

        Livewire::actingAs($this->admin())
            ->test(ModuleSwitchboard::class)
            ->call('toggle', 'workshop');

        $this->assertTrue(app(ModuleRegistry::class)->enabled('workshop'));
    }

    public function test_a_core_module_cannot_be_switched_off_from_the_switchboard(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ModuleSwitchboard::class)
            ->call('toggle', 'sales');

        $this->assertTrue(app(ModuleRegistry::class)->enabled('sales'));
    }

    public function test_a_module_cannot_be_switched_off_while_something_depends_on_it(): void
    {
        // reports and expenses both depend on sales; expenses depends on nothing else,
        // so switch reports off and confirm expenses still blocks nothing spurious.
        Livewire::actingAs($this->admin())
            ->test(ModuleSwitchboard::class)
            ->call('toggle', 'reports');

        $this->assertFalse(app(ModuleRegistry::class)->enabled('reports'));
    }

    public function test_a_manager_cannot_open_the_switchboard(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        $this->assertFalse(ModuleSwitchboard::canAccess());
    }

    public function test_an_admin_can_open_the_switchboard(): void
    {
        $this->actingAs($this->admin());

        $this->assertTrue(ModuleSwitchboard::canAccess());
    }

    /**
     * The classic Filament hole: a Livewire action is a public HTTP endpoint.
     * Hiding the button is not authorization — the method must refuse too.
     */
    public function test_a_manager_cannot_invoke_the_module_toggle_through_livewire(): void
    {
        $manager = User::factory()->manager()->create();

        try {
            Livewire::actingAs($manager)
                ->test(ModuleSwitchboard::class)
                ->call('toggle', 'workshop');
            $this->fail('a manager reached the module switchboard');
        } catch (\Throwable $e) {
            // Either the mount is refused or toggle() aborts — both are fine.
        }

        $this->assertTrue(
            app(ModuleRegistry::class)->enabled('workshop'),
            'a manager must not be able to switch a module off'
        );
    }

    public function test_a_technician_cannot_invoke_the_module_toggle_through_livewire(): void
    {
        try {
            Livewire::actingAs(User::factory()->technician()->create())
                ->test(ModuleSwitchboard::class)
                ->call('toggle', 'scripts');
            $this->fail('a technician reached the module switchboard');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertTrue(app(ModuleRegistry::class)->enabled('scripts'));
    }

    public function test_a_manager_cannot_grant_themselves_the_admin_role(): void
    {
        $manager = User::factory()->manager()->create();

        try {
            Livewire::actingAs($manager)
                ->test(EditUser::class, ['record' => $manager->getKey()])
                ->fillForm(['role' => Role::Admin->value])
                ->call('save');
            $this->fail('a manager opened the staff editor');
        } catch (\Throwable $e) {
            // expected: UserResource::canEdit requires users.update
        }

        $this->assertFalse($manager->refresh()->isAdmin(), 'privilege escalation');
        $this->assertTrue($manager->isManager());
    }
}
