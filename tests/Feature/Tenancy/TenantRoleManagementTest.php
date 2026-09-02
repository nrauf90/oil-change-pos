<?php

namespace Tests\Feature\Tenancy;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Permission as PermissionModel;
use App\Models\Role as RoleModel;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_a_custom_role_with_effective_registered_permissions(): void
    {
        $owner = User::factory()->admin()->create();

        Livewire::actingAs($owner)
            ->test(CreateRole::class)
            ->fillForm([
                'name' => 'service adviser',
                'description' => 'Runs customer check-in without seeing margins.',
                'permission_groups' => [
                    'sales' => [
                        Permission::UsePos->value,
                        Permission::ViewAnySale->value,
                    ],
                    'reports' => [
                        Permission::ViewDashboard->value,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = RoleModel::query()->where('name', 'service adviser')->sole();
        $permissionNames = $role->permissions()->orderBy('name')->pluck('name')->all();

        $this->assertSame('web', $role->guard_name);
        $this->assertSame('Runs customer check-in without seeing margins.', $role->description);
        $this->assertSame([
            Permission::UsePos->value,
            Permission::ViewDashboard->value,
            Permission::ViewAnySale->value,
        ], $permissionNames);
    }

    public function test_custom_role_assignment_replaces_the_previous_staff_role(): void
    {
        $owner = User::factory()->admin()->create();
        $customRole = RoleModel::query()->create([
            'name' => 'inventory lead',
            'guard_name' => 'web',
            'description' => 'Receives and adjusts stock.',
        ]);
        $customRole->syncPermissions([
            Permission::ViewAnyItem->value,
            Permission::ManageStock->value,
        ]);
        $staff = User::factory()->manager()->create();

        Livewire::actingAs($owner)
            ->test(EditUser::class, ['record' => $staff->getKey()])
            ->fillForm(['role' => $customRole->name])
            ->call('save')
            ->assertHasNoFormErrors();

        $staff = $staff->fresh();

        $this->assertSame(['inventory lead'], $staff->roles()->pluck('name')->all());
        $this->assertTrue($staff->can(Permission::ManageStock->value));
        $this->assertFalse($staff->can(Permission::UsePos->value));
    }

    public function test_role_management_requires_the_dedicated_permission(): void
    {
        $owner = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($owner);
        $this->assertTrue(RoleResource::canViewAny());
        $this->assertTrue(RoleResource::canCreate());

        $this->actingAs($manager);
        $this->assertFalse(RoleResource::canViewAny());
        $this->assertFalse(RoleResource::canCreate());
    }

    public function test_crafted_role_creation_rejects_a_permission_from_a_disabled_module(): void
    {
        $owner = User::factory()->admin()->create();
        app(ModuleRegistry::class)->setEnabled('scripts', false);

        Livewire::actingAs($owner)
            ->test(CreateRole::class)
            ->fillForm([
                'name' => 'restricted role',
                'description' => 'Must not receive unavailable permissions.',
            ])
            ->set('data.permission_groups.scripts', [Permission::ViewScripts->value])
            ->call('create')
            ->assertHasFormErrors(['permission_groups.scripts']);

        $this->assertDatabaseMissing('roles', [
            'name' => 'restricted role',
            'guard_name' => 'web',
        ], 'tenant');
    }

    public function test_final_active_administrator_cannot_be_assigned_to_a_custom_role(): void
    {
        $administrator = User::factory()->admin()->create();
        $editor = User::factory()->manager()->create();
        $editor->givePermissionTo(
            Permission::ViewAnyUser->value,
            Permission::UpdateUser->value,
        );
        $customRole = RoleModel::query()->create([
            'name' => 'office lead',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $customRole->syncPermissions([Permission::ViewAnyUser->value]);

        Livewire::actingAs($editor)
            ->test(EditUser::class, ['record' => $administrator->getKey()])
            ->set('data.role', $customRole->name)
            ->call('save')
            ->assertHasFormErrors(['role']);

        $this->assertSame([Role::Admin->value], $administrator->fresh()->roles()->pluck('name')->all());
    }

    public function test_signed_in_administrator_cannot_deactivate_themselves_when_another_admin_is_active(): void
    {
        $administrator = User::factory()->admin()->create();
        User::factory()->admin()->create();

        Livewire::actingAs($administrator)
            ->test(EditUser::class, ['record' => $administrator->getKey()])
            ->set('data.is_active', false)
            ->call('save')
            ->assertHasFormErrors(['is_active']);

        $this->assertTrue($administrator->fresh()->is_active);
    }

    public function test_administrator_role_identity_cannot_be_changed_by_a_crafted_request(): void
    {
        $owner = User::factory()->admin()->create();
        $administratorRole = RoleModel::query()->where('name', Role::Admin->value)->sole();

        Livewire::actingAs($owner)
            ->test(EditRole::class, ['record' => $administratorRole->getKey()])
            ->set('data.name', 'former administrator')
            ->call('save')
            ->assertHasFormErrors(['name']);

        $this->assertSame(Role::Admin->value, $administratorRole->fresh()->name);
    }

    public function test_administrator_role_always_retains_every_registered_permission(): void
    {
        $owner = User::factory()->admin()->create();
        $administratorRole = RoleModel::query()->where('name', Role::Admin->value)->sole();

        Livewire::actingAs($owner)
            ->test(EditRole::class, ['record' => $administratorRole->getKey()])
            ->set('data.permission_groups', [])
            ->call('save')
            ->assertHasNoFormErrors();

        $expected = PermissionModel::query()->orderBy('name')->pluck('name')->all();
        $actual = $administratorRole->fresh()->permissions()->orderBy('name')->pluck('name')->all();

        $this->assertSame($expected, $actual);
    }
}
