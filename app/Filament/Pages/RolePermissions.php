<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Enums\Role;
use App\Support\RolePermissionMatrix;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The role/permission matrix, visible and editable by the owner.
 *
 * The enums ship the defaults, but a shop that wants its manager to see margins
 * — or not to void sales — should not need a developer. Every permission the
 * application declares is listed here with a tick box per role.
 */
class RolePermissions extends Page
{
    protected string $view = 'filament.pages.role-permissions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Role Permissions';

    protected static ?string $title = 'Role Permissions';

    protected static ?int $navigationSort = 98;

    /**
     * Its own key, deliberately not `modules.manage`: whoever can rewrite this
     * matrix can grant themselves anything, so it is the most dangerous screen
     * in the back office and should be handed out on purpose.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManageRoles->value) ?? false;
    }

    /** @return array<int, array{value: string, label: string, description: string, locked: bool}> */
    public function getRoleColumns(): array
    {
        $matrix = $this->matrix();

        return array_map(fn (Role $role) => [
            'value' => $role->value,
            'label' => $role->label(),
            'description' => $role->description(),
            'locked' => $matrix->isImmutable($role),
        ], Role::cases());
    }

    /** @return array<int, array{name: string, permissions: array<int, array<string, mixed>>}> */
    public function getPermissionGroups(): array
    {
        return $this->matrix()->groups();
    }

    /**
     * A Livewire action is a public HTTP endpoint, so it re-checks access itself
     * rather than trusting that the checkbox was only rendered for an admin.
     */
    public function toggle(string $role, string $permission): void
    {
        abort_unless(static::canAccess(), 403);

        $roleEnum = Role::tryFrom($role);
        $permissionEnum = Permission::tryFrom($permission);

        if (! $roleEnum instanceof Role || ! $permissionEnum instanceof Permission) {
            return;
        }

        $matrix = $this->matrix();
        $wasHeld = $matrix->holds($roleEnum, $permissionEnum);

        if (! $matrix->toggle($roleEnum, $permissionEnum)) {
            Notification::make()
                ->title("{$roleEnum->label()} cannot be edited")
                ->body('The owner role holds every permission by design — taking one away would leave nobody able to put it back.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title($wasHeld ? "Revoked {$permissionEnum->value}" : "Granted {$permissionEnum->value}")
            ->body(($wasHeld ? 'Taken from ' : 'Given to ').$roleEnum->label().'. It applies on their next request.')
            ->success()
            ->send();
    }

    public function resetToDefaults(string $role): void
    {
        abort_unless(static::canAccess(), 403);

        $roleEnum = Role::tryFrom($role);

        if (! $roleEnum instanceof Role) {
            return;
        }

        $this->matrix()->resetToDefaults($roleEnum);

        Notification::make()
            ->title("{$roleEnum->label()} reset")
            ->body('This role now holds exactly the permissions the system shipped with.')
            ->success()
            ->send();
    }

    private function matrix(): RolePermissionMatrix
    {
        return app(RolePermissionMatrix::class);
    }
}
