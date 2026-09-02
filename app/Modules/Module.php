<?php

namespace App\Modules;

use App\Enums\Permission;

/**
 * One pluggable feature of the shop system.
 *
 * A module owns its permissions and its navigation, and can be switched off
 * from the admin panel without touching code. Everything a feature needs to
 * announce about itself lives in its Module subclass, so adding a feature is
 * "write the class, register it" rather than editing six shared files.
 */
abstract class Module
{
    /** Stable machine key. Persisted in the `modules` table — never rename casually. */
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** An outlined Heroicon name for the nav and the admin toggle list. */
    public function icon(): string
    {
        return 'heroicon-o-cog-6-tooth';
    }

    /**
     * Permissions this module owns. Used to seed the permission tables and to
     * hide irrelevant permissions in the admin UI when the module is off.
     *
     * @return array<int, Permission>
     */
    abstract public function permissions(): array;

    /**
     * Navigation entries this module contributes to the header.
     *
     * An entry may name the `group` it belongs in. There are two:
     *
     *   'main'    — the counter's daily work, shown as a link in the top bar.
     *   'account' — occasional, per-person screens, tucked into the profile
     *               menu so eight everyday links stay on one readable row.
     *
     * Omitting the key means 'main', so a module written before grouping
     * existed keeps its place in the bar.
     *
     * @return array<int, array{route: string, pattern: string, label: string, permission: ?Permission, group?: string, icon?: string}>
     */
    public function navigation(): array
    {
        return [];
    }

    /**
     * Modules that must be enabled for this one to work. The admin panel
     * refuses to disable a module while an enabled module still depends on it.
     *
     * @return array<int, string>
     */
    public function dependsOn(): array
    {
        return [];
    }

    /**
     * A core module is load-bearing: the app is not usable without it, so the
     * admin panel shows it as permanently on rather than offering a switch.
     */
    public function isCore(): bool
    {
        return false;
    }

    /** Whether a fresh install starts with this module switched on. */
    public function enabledByDefault(): bool
    {
        return true;
    }

    /** @return array<int, string> */
    public function permissionNames(): array
    {
        return array_map(fn (Permission $permission) => $permission->value, $this->permissions());
    }
}
