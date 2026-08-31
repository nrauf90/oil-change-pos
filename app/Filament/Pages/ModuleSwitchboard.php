<?php

namespace App\Filament\Pages;

use App\Enums\Permission;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The plug-and-play switchboard: turn a feature of the shop system on or off
 * without touching code. Disabling a module hides its navigation and 404s its
 * routes immediately.
 */
class ModuleSwitchboard extends Page
{
    protected string $view = 'filament.pages.module-switchboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Modules';

    protected static ?string $title = 'Modules';

    protected static ?int $navigationSort = 99;

    /**
     * Switching features of the shop on and off is its own power. This used to
     * be gated on `logs.view`, which meant granting somebody read access to the
     * audit trail silently handed them the switchboard as well.
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ManageModules->value) ?? false;
    }

    /** @return array<int, array<string, mixed>> */
    public function getModuleRows(): array
    {
        $registry = app(ModuleRegistry::class);

        return $registry->all()
            ->map(fn (Module $module) => [
                'key' => $module->key(),
                'title' => $module->title(),
                'description' => $module->description(),
                'icon' => $module->icon(),
                'core' => $module->isCore(),
                'enabled' => $registry->enabled($module->key()),
                'permissions' => $module->permissionNames(),
                'dependsOn' => $module->dependsOn(),
                'blockedBy' => $registry->enabledDependents($module->key())
                    ->map(fn (Module $dependent) => $dependent->title())
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    public function toggle(string $key): void
    {
        abort_unless(static::canAccess(), 403);

        $registry = app(ModuleRegistry::class);
        $module = $registry->find($key);

        if (! $module instanceof Module) {
            return;
        }

        if ($module->isCore()) {
            Notification::make()
                ->title("{$module->title()} is a core module")
                ->body('The shop system cannot run without it, so it cannot be switched off.')
                ->warning()
                ->send();

            return;
        }

        $turningOff = $registry->enabled($key);

        // Refuse to pull the rug out from under a feature that is still on.
        if ($turningOff) {
            $blockers = $registry->enabledDependents($key);

            if ($blockers->isNotEmpty()) {
                Notification::make()
                    ->title("Switch off {$blockers->map(fn (Module $m) => $m->title())->join(', ', ' and ')} first")
                    ->body("They depend on {$module->title()}.")
                    ->danger()
                    ->send();

                return;
            }
        }

        $registry->setEnabled($key, ! $turningOff);

        Notification::make()
            ->title($module->title().($turningOff ? ' switched off' : ' switched on'))
            ->body($turningOff
                ? 'Its screens are now hidden and its routes return 404.'
                : 'Its screens are available again.')
            ->success()
            ->send();
    }
}
