# Administration

## Purpose and workflow

Administration owns staff accounts, role-permission configuration, module switches, supplier navigation, and audit access. Filament provides the administrative UI while the normal application header consumes the same module registry.

## Code map

- Module: [`AdminModule`](../../app/Modules/Features/AdminModule.php), [`ModuleRegistry`](../../app/Modules/ModuleRegistry.php), [`Module`](../../app/Modules/Module.php)
- Module persistence/middleware: [`ModuleSetting`](../../app/Models/ModuleSetting.php), [`modules migration`](../../database/migrations/2026_08_26_180000_create_modules_table.php), [`EnsureModuleIsEnabled`](../../app/Http/Middleware/EnsureModuleIsEnabled.php)
- Filament: [`AdminPanelProvider`](../../app/Providers/Filament/AdminPanelProvider.php), [`ModuleSwitchboard`](../../app/Filament/Pages/ModuleSwitchboard.php), [`RolePermissions`](../../app/Filament/Pages/RolePermissions.php), [`UserResource`](../../app/Filament/Resources/Users/UserResource.php)
- Role engine: [`RolePermissionMatrix`](../../app/Support/RolePermissionMatrix.php)
- App navigation: [`app layout`](../../resources/views/layouts/app.blade.php)
- Tests: [`AdminPanelTest`](../../tests/Feature/AdminPanelTest.php), [`ModuleRegistryTest`](../../tests/Feature/ModuleRegistryTest.php), [`RolePermissionsTest`](../../tests/Feature/RolePermissionsTest.php), [`AdminInventoryTest`](../../tests/Feature/AdminInventoryTest.php)

## Permissions and invariants

- `admin`, `sales`, and `inventory` are core modules and cannot be disabled. Optional modules may declare dependencies; disabling a dependency is blocked while dependents remain enabled.
- `modules.manage`, `roles.manage`, and individual `users.*` permissions are deliberately separate.
- Unknown module keys are disabled. Navigation is filtered by both enabled module and user permission.
- Persist durable feature membership in a module class, not ad-hoc menu logic.

