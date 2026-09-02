<?php

namespace App\Filament\Resources\Roles;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\Roles\Tables\RolesTable;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 98;

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('guard_name', 'web')
            ->withCount([
                'permissions',
                'users as active_users_count' => fn (Builder $query): Builder => $query->where('is_active', true),
            ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(PermissionEnum::ManageRoles->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function isBuiltIn(?Model $role): bool
    {
        return $role instanceof Role && RoleEnum::tryFrom($role->name) instanceof RoleEnum;
    }

    public static function isAdministrator(?Model $role): bool
    {
        return $role instanceof Role && $role->name === RoleEnum::Admin->value;
    }

    public static function displayName(Role $role): string
    {
        return RoleEnum::tryFrom($role->name)?->label() ?? Str::headline($role->name);
    }

    public static function displayDescription(Role $role): ?string
    {
        return RoleEnum::tryFrom($role->name)?->description() ?? $role->description;
    }

    public static function moduleIcon(string $moduleKey): Heroicon
    {
        return match ($moduleKey) {
            'sales' => Heroicon::OutlinedShoppingCart,
            'inventory' => Heroicon::OutlinedCube,
            'reports' => Heroicon::OutlinedChartBar,
            'expenses' => Heroicon::OutlinedBanknotes,
            'scripts' => Heroicon::OutlinedChatBubbleLeftRight,
            'workshop' => Heroicon::OutlinedWrenchScrewdriver,
            default => Heroicon::OutlinedShieldCheck,
        };
    }

    /** @return array<int, string> */
    public static function registeredPermissionNames(): array
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /** @return array<int, string> */
    public static function effectivePermissionNames(): array
    {
        $registered = array_flip(static::registeredPermissionNames());

        return resolve(ModuleRegistry::class)
            ->enabledModules()
            ->flatMap(fn (Module $module): array => $module->permissionNames())
            ->filter(fn (string $permission): bool => isset($registered[$permission]))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{
     *     key: string,
     *     title: string,
     *     description: string,
     *     enabled: bool,
     *     options: array<string, string>
     * }>
     */
    public static function permissionGroups(): array
    {
        $registry = resolve(ModuleRegistry::class);
        $registered = array_flip(static::registeredPermissionNames());
        $groups = [];

        foreach ($registry->all() as $module) {
            $options = collect($module->permissionNames())
                ->filter(fn (string $permission): bool => isset($registered[$permission]))
                ->mapWithKeys(fn (string $permission): array => [
                    $permission => PermissionEnum::tryFrom($permission)?->label() ?? Str::headline($permission),
                ])
                ->all();

            if ($options === []) {
                continue;
            }

            $groups[$module->key()] = [
                'key' => $module->key(),
                'title' => $module->title(),
                'description' => $module->description(),
                'enabled' => $registry->enabled($module->key()),
                'options' => $options,
            ];
        }

        return $groups;
    }

    /** @return array<string, array<int, string>> */
    public static function permissionGroupState(Role $role): array
    {
        $held = array_flip($role->permissions()->pluck('name')->all());

        return collect(static::permissionGroups())
            ->map(fn (array $group): array => array_values(array_filter(
                array_keys($group['options']),
                fn (string $permission): bool => isset($held[$permission]),
            )))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $groups
     * @param  array<int, string>  $allowedUnavailablePermissions
     * @return array<int, string>
     */
    public static function invalidPermissionGroups(array $groups, array $allowedUnavailablePermissions = []): array
    {
        $definitions = static::permissionGroups();
        $effective = array_flip(static::effectivePermissionNames());
        $allowedUnavailable = array_flip($allowedUnavailablePermissions);
        $invalidGroups = [];

        foreach ($groups as $groupKey => $permissions) {
            $definition = $definitions[$groupKey] ?? null;

            if (! is_array($permissions) || ! is_array($definition)) {
                $invalidGroups[] = (string) $groupKey;

                continue;
            }

            foreach ($permissions as $permission) {
                if (! is_string($permission)
                    || ! array_key_exists($permission, $definition['options'])
                    || (! isset($effective[$permission]) && ! isset($allowedUnavailable[$permission]))) {
                    $invalidGroups[] = (string) $groupKey;

                    break;
                }
            }
        }

        return array_values(array_unique($invalidGroups));
    }

    /**
     * @param  array<string, mixed>  $groups
     * @return array<int, string>
     */
    public static function selectedPermissionNames(array $groups): array
    {
        return collect($groups)
            ->filter(fn (mixed $permissions): bool => is_array($permissions))
            ->flatten()
            ->filter(fn (mixed $permission): bool => is_string($permission))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public static function unavailableAssignedPermissionNames(Role $role): array
    {
        $effective = array_flip(static::effectivePermissionNames());
        $registered = array_flip(static::registeredPermissionNames());

        return $role->permissions()
            ->pluck('name')
            ->filter(fn (string $permission): bool => isset($registered[$permission]) && ! isset($effective[$permission]))
            ->values()
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
