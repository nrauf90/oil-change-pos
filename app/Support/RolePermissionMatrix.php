<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Role as RoleModel;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reads and writes the role/permission matrix that the back office screen shows.
 *
 * The enums stay the shipped defaults; this class is the only thing allowed to
 * move the live matrix away from them, so the two rules that keep a shop out of
 * trouble — the admin is untouchable, and every write flushes spatie's cache —
 * live in one place rather than being re-implemented by each caller.
 */
class RolePermissionMatrix
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * The whole matrix, permissions in enum order, bucketed by their group.
     *
     * @return array<int, array{name: string, permissions: array<int, array<string, mixed>>}>
     */
    public function groups(): array
    {
        $held = $this->heldByRole();
        $owners = $this->moduleByPermission();

        $groups = [];

        foreach (Permission::cases() as $permission) {
            $module = $owners[$permission->value] ?? null;

            $roles = [];

            foreach (Role::cases() as $role) {
                $roles[$role->value] = [
                    'held' => $this->isImmutable($role) || in_array($permission->value, $held[$role->value], true),
                    'locked' => $this->isImmutable($role),
                ];
            }

            $groups[$permission->group()]['name'] = $permission->group();
            $groups[$permission->group()]['permissions'][] = [
                'name' => $permission->value,
                'label' => $permission->label(),
                'module' => $module?->title(),
                // Shown, never hidden: a permission whose feature is switched off
                // still matters the moment somebody switches the feature back on.
                'moduleOff' => $module instanceof Module && ! $this->modules->enabled($module->key()),
                'roles' => $roles,
            ];
        }

        return array_values($groups);
    }

    /** Whether the live matrix currently gives this role this permission. */
    public function holds(Role $role, Permission $permission): bool
    {
        if ($this->isImmutable($role)) {
            return true;
        }

        return in_array($permission->value, $this->heldByRole()[$role->value], true);
    }

    /**
     * Flip one cell. Returns false when the change was refused, so the caller can
     * say so rather than reporting a write that never happened.
     */
    public function toggle(Role $role, Permission $permission): bool
    {
        return $this->holds($role, $permission)
            ? $this->revoke($role, $permission)
            : $this->grant($role, $permission);
    }

    public function grant(Role $role, Permission $permission): bool
    {
        $this->model($role)->givePermissionTo($permission->value);

        $this->flush();

        return true;
    }

    /**
     * Refuses to take anything away from the admin. This is the lockout footgun:
     * revoke `users.view_any` from the admin and there is nobody left who can put
     * it back, short of a developer with database access. The UI disables the
     * admin column, but the UI is not the guard — this is.
     */
    public function revoke(Role $role, Permission $permission): bool
    {
        if ($this->isImmutable($role)) {
            return false;
        }

        $this->model($role)->revokePermissionTo($permission->value);

        $this->flush();

        return true;
    }

    /** Put a role back to the bundle the application shipped with. */
    public function resetToDefaults(Role $role): void
    {
        $this->model($role)->syncPermissions($role->permissionNames());

        $this->flush();
    }

    /** The admin is all-powerful by definition and cannot be edited down. */
    public function isImmutable(Role $role): bool
    {
        return $role === Role::Admin;
    }

    private function model(Role $role): RoleModel
    {
        return RoleModel::where('name', $role->value)->firstOrFail();
    }

    /**
     * One query for the whole matrix rather than a `can()` per cell.
     *
     * @return array<string, array<int, string>>
     */
    private function heldByRole(): array
    {
        $held = array_fill_keys(Role::values(), []);

        foreach (RoleModel::with('permissions')->whereIn('name', Role::values())->get() as $role) {
            $held[$role->name] = $role->permissions->pluck('name')->all();
        }

        return $held;
    }

    /**
     * Which module owns each permission, so the screen can tag the ones whose
     * feature is currently switched off.
     *
     * @return array<string, Module>
     */
    private function moduleByPermission(): array
    {
        $owners = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->permissionNames() as $name) {
                $owners[$name] ??= $module;
            }
        }

        return $owners;
    }

    /**
     * Spatie caches the role/permission map, so a write that skips this looks
     * like it silently did nothing until the cache happens to expire. Its own
     * write methods flush too, but that is their internal business — this class
     * flushes explicitly so the guarantee survives however they are refactored.
     */
    private function flush(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
