<?php

namespace App\Modules;

use App\Enums\Permission;
use App\Models\ModuleSetting;
use Illuminate\Support\Collection;

/**
 * Holds every known module and answers "is this feature switched on?".
 *
 * Enabled state lives in the `modules` table and is cached for the request, so
 * the dozens of nav/permission checks on a page cost one query.
 */
class ModuleRegistry
{
    /** @var array<string, Module> */
    private array $modules = [];

    /** @var array<string, bool>|null */
    private ?array $enabledCache = null;

    /** @param array<int, Module> $modules */
    public function __construct(array $modules = [])
    {
        foreach ($modules as $module) {
            $this->register($module);
        }
    }

    public function register(Module $module): void
    {
        $this->modules[$module->key()] = $module;
    }

    /** @return Collection<string, Module> */
    public function all(): Collection
    {
        return collect($this->modules);
    }

    public function find(string $key): ?Module
    {
        return $this->modules[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->modules[$key]);
    }

    /**
     * A module the app does not know about is treated as OFF, so a stale row or
     * a typo'd middleware argument fails closed rather than opening a feature.
     */
    public function enabled(string $key): bool
    {
        $module = $this->find($key);

        if (! $module instanceof Module) {
            return false;
        }

        if ($module->isCore()) {
            return true;
        }

        return $this->enabledMap()[$key] ?? $module->enabledByDefault();
    }

    /** @return Collection<string, Module> */
    public function enabledModules(): Collection
    {
        return $this->all()->filter(fn (Module $module) => $this->enabled($module->key()));
    }

    public function setEnabled(string $key, bool $enabled): void
    {
        $module = $this->find($key);

        if (! $module instanceof Module || $module->isCore()) {
            return;
        }

        ModuleSetting::query()->updateOrCreate(
            ['key' => $key],
            ['enabled' => $enabled],
        );

        $this->flush();
    }

    /**
     * Enabled modules that depend on the given module — the admin panel blocks
     * turning a module off while any of these are still on.
     *
     * @return Collection<string, Module>
     */
    public function enabledDependents(string $key): Collection
    {
        return $this->enabledModules()
            ->filter(fn (Module $module) => in_array($key, $module->dependsOn(), true));
    }

    /**
     * Permissions contributed by every module, whether on or off — the seeder
     * needs them all so switching a module back on restores its permissions.
     *
     * @return array<int, string>
     */
    public function allPermissionNames(): array
    {
        return $this->all()
            ->flatMap(fn (Module $module) => $module->permissionNames())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Navigation for the header, filtered to enabled modules the user may see.
     *
     * Every entry comes back carrying a `group` and an `icon`, defaulted here
     * so the header never has to test whether a module bothered to set them.
     *
     * @return array<int, array{route: string, pattern: string, label: string, permission: ?Permission, group: string, icon: string}>
     */
    public function navigationFor(?object $user): array
    {
        return $this->enabledModules()
            ->flatMap(fn (Module $module) => $module->navigation())
            ->filter(function (array $entry) use ($user): bool {
                if ($entry['permission'] === null) {
                    return true;
                }

                return $user !== null && $user->can($entry['permission']->value);
            })
            ->map(fn (array $entry) => $entry + ['group' => 'main', 'icon' => ''])
            ->values()
            ->all();
    }

    /**
     * The same navigation, split into the groups the header renders: links in
     * the top bar, and entries tucked inside the profile menu.
     *
     * @return array<string, array<int, array{route: string, pattern: string, label: string, permission: ?Permission, group: string, icon: string}>>
     */
    public function groupedNavigationFor(?object $user): array
    {
        $grouped = collect($this->navigationFor($user))->groupBy('group');

        return [
            'main' => $grouped->get('main', collect())->values()->all(),
            'account' => $grouped->get('account', collect())->values()->all(),
        ];
    }

    public function flush(): void
    {
        $this->enabledCache = null;
    }

    /** @return array<string, bool> */
    private function enabledMap(): array
    {
        return $this->enabledCache ??= ModuleSetting::query()
            ->pluck('enabled', 'key')
            ->map(fn ($enabled) => (bool) $enabled)
            ->all();
    }
}
