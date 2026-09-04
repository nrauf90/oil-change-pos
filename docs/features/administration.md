# Administration

## Purpose and workflow

Administration owns staff accounts, role-permission configuration, module switches, supplier navigation, and audit access. Filament provides the administrative UI while the normal application header consumes the same module registry.

## Code map

- Module: [`AdminModule`](../../app/Modules/Features/AdminModule.php), [`ModuleRegistry`](../../app/Modules/ModuleRegistry.php), [`Module`](../../app/Modules/Module.php)
- Module persistence/middleware: [`ModuleSetting`](../../app/Models/ModuleSetting.php), [`modules migration`](../../database/migrations/tenant/2026_08_26_180000_create_modules_table.php), [`EnsureModuleIsEnabled`](../../app/Http/Middleware/EnsureModuleIsEnabled.php)
- Platform ceiling: [`TenantFeatureGate`](../../app/Tenancy/TenantFeatureGate.php), [`ShopFeature`](../../app/Models/Central/ShopFeature.php) — see [Platform control plane](platform-control-plane.md)
- Filament: [`AdminPanelProvider`](../../app/Providers/Filament/AdminPanelProvider.php), [`ModuleSwitchboard`](../../app/Filament/Pages/ModuleSwitchboard.php), [`RolePermissions`](../../app/Filament/Pages/RolePermissions.php), [`UserResource`](../../app/Filament/Resources/Users/UserResource.php), [`RoleResource`](../../app/Filament/Resources/Roles/RoleResource.php)
- Role engine: [`RolePermissionMatrix`](../../app/Support/RolePermissionMatrix.php), [`ManageTenantUsers`](../../app/Actions/ManageTenantUsers.php)
- App navigation: [`app layout`](../../resources/views/layouts/app.blade.php)
- Tests: [`AdminPanelTest`](../../tests/Feature/AdminPanelTest.php), [`ModuleRegistryTest`](../../tests/Feature/ModuleRegistryTest.php), [`RolePermissionsTest`](../../tests/Feature/RolePermissionsTest.php), [`AdminInventoryTest`](../../tests/Feature/AdminInventoryTest.php)

## Permissions and invariants

- `admin`, `sales`, and `inventory` are core modules and cannot be disabled. Optional modules may declare dependencies; disabling a dependency is blocked while dependents remain enabled.
- A module is effectively on only when the platform entitlement *and* the shop preference both allow it. The switchboard shows a platform-withheld module as unavailable and refuses to enable it; only a platform administrator can lift that ceiling.
- The three built-in roles come from `App\Enums\Role`; owners may add custom roles through `RoleResource`. The Administrator role is immutable and always holds every registered permission, and the last active administrator cannot be demoted, deactivated or deleted.
- `modules.manage`, `roles.manage`, and individual `users.*` permissions are deliberately separate.
- Unknown module keys are disabled. Navigation is filtered by both enabled module and user permission.
- Persist durable feature membership in a module class, not ad-hoc menu logic.

