# SaaS Multi-Tenant POS Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the existing POS into a database-per-shop SaaS foundation with a central super-admin panel, isolated tenant authentication/data, provisioning, feature control, audited support access, statistics, and migration tooling.

**Architecture:** A central Laravel connection stores platform identities and shop metadata; a request-scoped `TenantContext` configures a separate `tenant` connection before tenant authentication. Existing operational models remain tenant-local, while a new Filament platform panel manages shops without allowing unscoped operational queries.

**Tech Stack:** PHP 8.3, Laravel 13.29, Filament 5.7, Spatie Laravel Permission 8.3, PHPUnit 12.5, MySQL production databases, SQLite tenant databases in tests, Blade/Tailwind/Vite.

**Spec:** `docs/superpowers/specs/2026-09-01-saas-multi-tenant-foundation-design.md`

## Global Constraints

- Use Laravel native dynamic database connections; do not add a tenancy package.
- Central data and tenant operational data must use explicit, separate connection contracts and fail closed without tenant context.
- Existing `User`, roles, permissions, sales, inventory, expenses, vehicles, modules, and activity remain tenant-local.
- Platform authentication uses a separate `platform` guard and never satisfies tenant authorization.
- Production tenant routing uses shop slugs; local/test route resolution must call the same resolver contract.
- Platform support access is read-only, audited, and visually identified by a persistent banner.
- Feature entitlement is a platform ceiling over the tenant module preference; disabling features never deletes tenant data.
- No command or failed provisioning rollback may implicitly drop a tenant database.
- All new behavior follows strict red-green-refactor TDD with focused PHPUnit evidence.
- UI work is browser-verified desktop first at 1440×900, then 768×900 and 390×844.
- Use Heroicons and existing amber theme; do not introduce emoji interface icons.
- Forms use explicit responsive grids, 36-pixel adjacent controls, stable card placement, and no horizontal overflow.
- Do not modify or discard unrelated dirty work from the original main workspace.

---

## File Structure

### Central domain

- `app/Models/Central/CentralModel.php`: base model pinned to `central`.
- `app/Models/Central/PlatformUser.php`: platform-auth identity.
- `app/Models/Central/Shop.php`: tenant descriptor and lifecycle state.
- `app/Models/Central/ShopOwner.php`: central owner/contact record.
- `app/Models/Central/ShopFeature.php`: platform feature ceiling.
- `app/Models/Central/ShopAccessSession.php`: immutable support audit.
- `app/Models/Central/ShopHealthSnapshot.php`: cached platform summaries.
- `app/Enums/ShopStatus.php`: lifecycle enum.
- `database/migrations/central/*`: only central tables.

### Tenant infrastructure

- `app/Tenancy/TenantContext.php`: immutable current-shop state.
- `app/Tenancy/TenantConnectionManager.php`: configure/purge tenant connection.
- `app/Tenancy/TenantResolver.php`: trusted request-to-shop contract.
- `app/Tenancy/CentralTenantResolver.php`: production resolver.
- `app/Http/Middleware/InitializeTenancy.php`: resolve and initialize before auth.
- `app/Http/Middleware/EnsureShopIsActive.php`: fail-closed status gate.
- `app/Models/TenantModel.php`: base operational model connection contract.
- `database/migrations/tenant/*`: operational schema source.

### Provisioning and operations

- `app/Tenancy/Provisioning/DatabaseProvisioner.php`: provisioner contract.
- `app/Tenancy/Provisioning/MySqlDatabaseProvisioner.php`: quoted MySQL database creation.
- `app/Tenancy/Provisioning/SqliteDatabaseProvisioner.php`: test/local file creation.
- `app/Actions/Tenancy/ProvisionShop.php`: idempotent provisioning state machine.
- `app/Actions/Tenancy/CollectShopHealth.php`: connection/migration/seed health.
- `app/Actions/Tenancy/CollectTenantStatistics.php`: selected-shop metrics.
- `app/Console/Commands/TenantsProvisionCommand.php`: provision one shop.
- `app/Console/Commands/TenantsMigrateCommand.php`: migrate one/all shops.
- `app/Console/Commands/TenantsHealthCommand.php`: health one/all shops.
- `app/Console/Commands/TenantsAdoptExistingCommand.php`: dry-run-first adoption.

### Platform UI

- `app/Providers/Filament/PlatformPanelProvider.php`: `/platform` panel and guard.
- `app/Filament/Platform/Pages/PlatformDashboard.php`: platform overview.
- `app/Filament/Platform/Resources/Shops/*`: create/list/view shop workflows.
- `app/Filament/Platform/Resources/PlatformUsers/*`: super-admin management.
- `app/Filament/Platform/Resources/ShopAccessSessions/*`: audit table.
- `resources/css/filament/platform/theme.css`: platform theme matching admin.

### Tenant authorization, entitlements, and support

- `app/Actions/Tenancy/SyncTenantAuthorization.php`: idempotent permissions/roles.
- `app/Modules/ModuleRegistry.php`: combine platform ceiling and tenant preference.
- `app/Tenancy/TenantFeatureGate.php`: central entitlement reader.
- `app/Tenancy/SupportAccessManager.php`: signed audited support lifecycle.
- `app/Http/Middleware/InitializeSupportAccess.php`: read-only support context.
- `app/Http/Middleware/EnforceReadOnlySupportAccess.php`: reject mutations.
- `resources/views/components/support-access-banner.blade.php`: persistent banner.

---

### Task 1: Central Database and Platform Domain

**Files:**
- Modify: `config/database.php`
- Modify: `config/auth.php`
- Create: `app/Enums/ShopStatus.php`
- Create: `app/Models/Central/CentralModel.php`
- Create: `app/Models/Central/PlatformUser.php`
- Create: `app/Models/Central/Shop.php`
- Create: `app/Models/Central/ShopOwner.php`
- Create: `app/Models/Central/ShopFeature.php`
- Create: `app/Models/Central/ShopAccessSession.php`
- Create: `app/Models/Central/ShopHealthSnapshot.php`
- Create: `database/migrations/central/2026_09_01_000001_create_platform_users_table.php`
- Create: `database/migrations/central/2026_09_01_000002_create_shops_table.php`
- Create: `database/migrations/central/2026_09_01_000003_create_shop_owners_table.php`
- Create: `database/migrations/central/2026_09_01_000004_create_shop_features_table.php`
- Create: `database/migrations/central/2026_09_01_000005_create_shop_access_sessions_table.php`
- Create: `database/migrations/central/2026_09_01_000006_create_shop_health_snapshots_table.php`
- Create: `database/factories/Central/PlatformUserFactory.php`
- Create: `database/factories/Central/ShopFactory.php`
- Test: `tests/Feature/Tenancy/CentralDomainTest.php`

**Interfaces:**
- Produces: `ShopStatus: string enum`, central models on connection `central`, `PlatformUser` authenticatable on provider `platform_users`, `Shop::databaseConfig(): array<string,mixed>`.
- Consumes: existing environment database settings as defaults; encrypted casts for connection overrides.

- [ ] **Step 1: Create the failing central-domain test**

```php
public function test_central_models_never_use_the_tenant_connection(): void
{
    $shop = Shop::factory()->create(['status' => ShopStatus::Active]);

    $this->assertSame('central', $shop->getConnectionName());
    $this->assertSame('central', (new PlatformUser)->getConnectionName());
    $this->assertSame('active', $shop->status->value);
}
```

Also assert encrypted fields are absent from array/JSON output and the `platform` guard provider resolves `PlatformUser`.

- [ ] **Step 2: Run the test and verify RED**

Run: `php artisan test --compact tests/Feature/Tenancy/CentralDomainTest.php`

Expected: FAIL because central models, connection, migrations, and guard do not exist.

- [ ] **Step 3: Implement central connection, schema, models, factories, and guard**

Use explicit `protected $connection = 'central';`, UUID shop keys, `ShopStatus` enum casts, hidden encrypted connection fields, and relationships with return types. Add `central` and `tenant` connection templates to `config/database.php`; do not change the environment's default connection yet.

- [ ] **Step 4: Run focused tests and refactor**

Run: `php artisan test --compact tests/Feature/Tenancy/CentralDomainTest.php`

Expected: PASS with central records isolated from tenant configuration.

- [ ] **Step 5: Format and commit**

Run: `vendor/bin/pint --dirty --format agent`

Commit: `feat: add central SaaS domain`

### Task 2: Tenant Context, Dynamic Connection, and Model Contract

**Files:**
- Create: `app/Tenancy/TenantContext.php`
- Create: `app/Tenancy/TenantConnectionManager.php`
- Create: `app/Tenancy/Exceptions/TenantNotInitialized.php`
- Create: `app/Models/TenantModel.php`
- Modify: operational models in `app/Models/*.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Tenancy/TenantConnectionIsolationTest.php`

**Interfaces:**
- Consumes: `Shop::databaseConfig()` from Task 1.
- Produces: `TenantContext::initialize(Shop $shop): void`, `TenantContext::shop(): Shop`, `TenantContext::id(): string`, `TenantContext::clear(): void`, `TenantConnectionManager::connect(Shop $shop): void`, `TenantConnectionManager::disconnect(): void`.

- [ ] **Step 1: Write failing two-database isolation tests**

Create two temporary tenant SQLite databases with identical migrations. Assert an item created under shop A is absent under shop B, and assert loading an operational model without initialized context throws `TenantNotInitialized`.

```php
$context->initialize($shopA);
Item::factory()->create(['name' => 'Shop A filter']);
$context->clear();
$context->initialize($shopB);
$this->assertDatabaseMissing('items', ['name' => 'Shop A filter'], 'tenant');
```

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --compact tests/Feature/Tenancy/TenantConnectionIsolationTest.php`

Expected: FAIL because operational models still use the default connection and context is absent.

- [ ] **Step 3: Implement context, manager, and tenant model enforcement**

Make operational models inherit `TenantModel` or apply an explicit reusable connection contract where inheritance is not possible. Configure `tenant`, call `DB::purge('tenant')`, reconnect, reset model resolvers, and clear state. `TenantModel::getConnectionName()` must throw before context initialization.

- [ ] **Step 4: Run isolation plus existing model tests**

Run: `php artisan test --compact tests/Feature/Tenancy/TenantConnectionIsolationTest.php tests/Feature/ItemManagementTest.php tests/Feature/CheckoutTest.php`

Expected: PASS after test bootstrap initializes a default test tenant explicitly.

- [ ] **Step 5: Format and commit**

Commit: `feat: enforce tenant database context`

### Task 3: Trusted Tenant Resolution and Middleware Ordering

**Files:**
- Create: `app/Tenancy/TenantResolver.php`
- Create: `app/Tenancy/CentralTenantResolver.php`
- Create: `app/Http/Middleware/InitializeTenancy.php`
- Create: `app/Http/Middleware/EnsureShopIsActive.php`
- Create: `app/Http/Responses/ShopUnavailableResponse.php`
- Create: `resources/views/errors/shop-unavailable.blade.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Tenancy/TenantResolutionTest.php`

**Interfaces:**
- Consumes: `TenantContext` and `TenantConnectionManager` from Task 2.
- Produces: `TenantResolver::resolve(Request $request): ?Shop`; middleware aliases `tenant` and `shop.active`.

- [ ] **Step 1: Write failing resolver and middleware tests**

Cover active slug resolution, unknown slug, suspended/provisioning/failed shops, unsigned query-string rejection, route-prefix test fallback, and context cleanup after response.

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --compact tests/Feature/Tenancy/TenantResolutionTest.php`

Expected: FAIL because resolver and middleware do not exist.

- [ ] **Step 3: Implement trusted resolution and fail-closed middleware**

Resolve configured base-domain subdomains first. Permit route fallback only when `app.env` is `local` or `testing`. Register tenancy middleware before `auth`, `permission`, bindings that resolve tenant models, and Livewire/Filament tenant endpoints.

- [ ] **Step 4: Verify behavior and regressions**

Run: `php artisan test --compact tests/Feature/Tenancy/TenantResolutionTest.php tests/Feature/AuthenticationTest.php`

Expected: PASS; unavailable pages reveal no credentials.

- [ ] **Step 5: Format and commit**

Commit: `feat: resolve and initialize shop tenancy`

### Task 4: Tenant Migration Layout and Test Harness

**Files:**
- Move: operational migrations from `database/migrations/*.php` to `database/migrations/tenant/*.php`
- Keep/Create: central bootstrap migration runner in `database/migrations/*.php` only if required by Laravel setup
- Create: `tests/Concerns/UsesTenantDatabases.php`
- Modify: `tests/TestCase.php`
- Modify: `phpunit.xml`
- Test: `tests/Feature/Tenancy/TenantMigrationTest.php`

**Interfaces:**
- Consumes: migration paths and tenant manager.
- Produces: `UsesTenantDatabases::createTenantDatabase(Shop $shop): string`, explicit central/tenant migration helpers used by later tests.

- [ ] **Step 1: Write failing migration-contract tests**

Assert central migration creates only central tables, tenant migration creates operational tables including users/items/sales, and neither schema contains the other's exclusive tables.

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --compact tests/Feature/Tenancy/TenantMigrationTest.php`

Expected: FAIL because migration paths are not separated.

- [ ] **Step 3: Move migrations and implement deterministic test helpers**

Preserve migration order and filenames. Configure tests with one temporary central SQLite file and a fresh tenant file per test. Never rely on a process-global tenant connection between tests.

- [ ] **Step 4: Run migration and full existing suite**

Run: `php artisan test --compact tests/Feature/Tenancy/TenantMigrationTest.php`

Then: `php artisan test --compact`

Expected: all existing behavior passes within an explicitly initialized tenant.

- [ ] **Step 5: Format and commit**

Commit: `refactor: separate central and tenant migrations`

### Task 5: Idempotent Tenant Authorization and Shop Provisioning

**Files:**
- Create: `app/Tenancy/Provisioning/DatabaseProvisioner.php`
- Create: `app/Tenancy/Provisioning/MySqlDatabaseProvisioner.php`
- Create: `app/Tenancy/Provisioning/SqliteDatabaseProvisioner.php`
- Create: `app/Actions/Tenancy/SyncTenantAuthorization.php`
- Create: `app/Actions/Tenancy/ProvisionShop.php`
- Create: `app/Data/ProvisionShopData.php`
- Create: `app/Console/Commands/TenantsProvisionCommand.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Tenancy/ShopProvisioningTest.php`

**Interfaces:**
- Produces: `DatabaseProvisioner::provision(Shop $shop): void`; `ProvisionShop::handle(ProvisionShopData $data): Shop`; `SyncTenantAuthorization::handle(User $owner): void`.
- Consumes: tenant migration path, role/permission module catalogue, TenantConnectionManager.

- [ ] **Step 1: Write failing provisioning tests**

Cover success, owner creation with hashed password, required roles/permissions/modules, duplicate retry, safe failure state, retry after failure, slug uniqueness, and no automatic database deletion.

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --compact tests/Feature/Tenancy/ShopProvisioningTest.php`

Expected: FAIL because provisioners and action do not exist.

- [ ] **Step 3: Implement minimal state machine and provisioners**

Use strict database-name validation (`^[A-Za-z0-9_]+$`) and driver-specific quoting. MySQL implementation may create databases but never drop them. Hash temporary passwords before tenant inserts and never persist plaintext centrally.

- [ ] **Step 4: Run provisioning tests and command smoke test**

Run: `php artisan test --compact tests/Feature/Tenancy/ShopProvisioningTest.php`

Run: `php artisan tenants:provision --help`

Expected: PASS and non-interactive command options are documented by Artisan.

- [ ] **Step 5: Format and commit**

Commit: `feat: provision isolated shop databases`

### Task 6: Platform Authentication and Filament Panel

**Files:**
- Create: `app/Providers/Filament/PlatformPanelProvider.php`
- Create: `app/Filament/Platform/Pages/PlatformDashboard.php`
- Create: `app/Filament/Platform/Widgets/ShopStatusOverview.php`
- Create: `app/Filament/Platform/Resources/PlatformUsers/PlatformUserResource.php`
- Create: `app/Filament/Platform/Resources/PlatformUsers/Pages/CreatePlatformUser.php`
- Create: `app/Filament/Platform/Resources/PlatformUsers/Pages/EditPlatformUser.php`
- Create: `app/Filament/Platform/Resources/PlatformUsers/Pages/ListPlatformUsers.php`
- Create: `resources/css/filament/platform/theme.css`
- Modify: `bootstrap/providers.php`
- Modify: `vite.config.js`
- Test: `tests/Feature/Platform/PlatformAuthenticationTest.php`
- Test: `tests/Feature/Platform/PlatformDashboardTest.php`

**Interfaces:**
- Consumes: central `PlatformUser`, `platform` guard, shops.
- Produces: Filament panel ID `platform`, path `/platform`, auth guard `platform`, amber theme.

- [ ] **Step 1: Write failing panel boundary tests**

Assert active super admin can access `/platform`; tenant owner cannot; platform user cannot access tenant admin; inactive platform user is rejected; dashboard status counts are correct; active super admins can create another platform user; and the final active super admin cannot deactivate or delete themselves.

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --compact tests/Feature/Platform/PlatformAuthenticationTest.php tests/Feature/Platform/PlatformDashboardTest.php`

Expected: FAIL because platform panel is absent.

- [ ] **Step 3: Implement panel, login, dashboard, and theme**

Use explicit panel discovery namespace `App\Filament\Platform`, central guard, no tenant resources, and consistent admin-panel spacing/icons. Do not register the Filament account welcome widget.

- [ ] **Step 4: Run tests and frontend build**

Run: `php artisan test --compact tests/Feature/Platform/PlatformAuthenticationTest.php tests/Feature/Platform/PlatformDashboardTest.php`

Run: `npm run build`

Expected: PASS with no Vite manifest error.

- [ ] **Step 5: Format and commit**

Commit: `feat: add super admin platform panel`

### Task 7: Platform Shop Management, Health, and Statistics

**Files:**
- Create: `app/Filament/Platform/Resources/Shops/ShopResource.php`
- Create: `app/Filament/Platform/Resources/Shops/Pages/CreateShop.php`
- Create: `app/Filament/Platform/Resources/Shops/Pages/ListShops.php`
- Create: `app/Filament/Platform/Resources/Shops/Pages/ViewShop.php`
- Create: `app/Filament/Platform/Resources/Shops/Schemas/ShopForm.php`
- Create: `app/Filament/Platform/Resources/Shops/Tables/ShopsTable.php`
- Create: `app/Actions/Tenancy/CollectShopHealth.php`
- Create: `app/Actions/Tenancy/CollectTenantStatistics.php`
- Test: `tests/Feature/Platform/ShopManagementTest.php`
- Test: `tests/Feature/Platform/TenantStatisticsTest.php`

**Interfaces:**
- Produces: `CollectShopHealth::handle(Shop $shop): ShopHealthSnapshot`; `CollectTenantStatistics::handle(Shop $shop, DashboardPeriod $period): array`.
- Consumes: `ProvisionShop`, tenant context, existing dashboard metrics.

- [ ] **Step 1: Write failing resource and statistics tests**

Cover create action, provisioning notification, safe status badges, suspend/reactivate, retry failed provisioning, selected-shop totals, no cross-shop totals, and credential redaction.

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --compact tests/Feature/Platform/ShopManagementTest.php tests/Feature/Platform/TenantStatisticsTest.php`

Expected: FAIL because resources and collectors do not exist.

- [ ] **Step 3: Implement explicit responsive shop UI and collectors**

Use stable two-column desktop grids and one-column mobile grids. Status badges: active green, provisioning amber, suspended gray, failed red. Destructive/suspension actions explain impact. Statistics initialize exactly one tenant context and clear it in `finally`.

- [ ] **Step 4: Run focused tests**

Run: `php artisan test --compact tests/Feature/Platform/ShopManagementTest.php tests/Feature/Platform/TenantStatisticsTest.php`

Expected: PASS without exposing connection secrets.

- [ ] **Step 5: Format and commit**

Commit: `feat: manage shops and tenant health`

### Task 8: Tenant Owners, Custom Roles, and Permission Cache Isolation

**Files:**
- Modify: `app/Models/User.php`
- Modify: `app/Filament/Resources/Users/*`
- Modify: `app/Filament/Pages/RolePermissions.php`
- Create: `app/Filament/Resources/Roles/RoleResource.php`
- Create: `app/Filament/Resources/Roles/Pages/CreateRole.php`
- Create: `app/Filament/Resources/Roles/Pages/EditRole.php`
- Create: `app/Filament/Resources/Roles/Pages/ListRoles.php`
- Create: `app/Tenancy/TenantPermissionCache.php`
- Test: `tests/Feature/Tenancy/TenantRoleManagementTest.php`
- Test: `tests/Feature/Tenancy/TenantPermissionIsolationTest.php`

**Interfaces:**
- Consumes: tenant-local Spatie tables and active context.
- Produces: tenant-keyed permission cache prefix; owner-managed roles limited to registered/effective permissions.

- [ ] **Step 1: Write failing authorization and cache tests**

Cover owner role creation, user assignment, cross-tenant same-role names, cache switching, final active administrator protection, self-deactivation protection, and forbidden assignment of platform-disabled permissions.

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --compact tests/Feature/Tenancy/TenantRoleManagementTest.php tests/Feature/Tenancy/TenantPermissionIsolationTest.php`

Expected: FAIL because custom role resource and cache scoping are absent.

- [ ] **Step 3: Implement role management and tenant cache reset**

Reset Spatie's registrar whenever tenant context initializes or clears. Preserve existing single-role user invariant. Enforce lockout rules in mutation hooks, not only disabled form controls.

- [ ] **Step 4: Run authorization regressions**

Run: `php artisan test --compact tests/Feature/Tenancy/TenantRoleManagementTest.php tests/Feature/Tenancy/TenantPermissionIsolationTest.php tests/Feature/StaffLockoutTest.php tests/Feature/RolePermissionsTest.php`

Expected: PASS.

- [ ] **Step 5: Format and commit**

Commit: `feat: add tenant role administration`

### Task 9: Platform Feature Entitlements

**Files:**
- Create: `app/Tenancy/TenantFeatureGate.php`
- Modify: `app/Modules/ModuleRegistry.php`
- Modify: `app/Filament/Pages/ModuleSwitchboard.php`
- Modify: `app/Http/Middleware/EnsureModuleIsEnabled.php`
- Modify: `app/Filament/Platform/Resources/Shops/Pages/ViewShop.php`
- Test: `tests/Feature/Tenancy/PlatformFeatureEntitlementTest.php`

**Interfaces:**
- Produces: `TenantFeatureGate::enabled(string $moduleKey): bool`; `ModuleRegistry::enabled()` evaluates platform ceiling and tenant preference.
- Consumes: central `ShopFeature`, immutable tenant context, module dependencies.

- [ ] **Step 1: Write failing entitlement tests**

Cover missing-row default, core modules, tenant-off, platform-off, route 404, navigation hiding, owner inability to override, dependency blocking, re-enable without data loss, and central audit creation.

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --compact tests/Feature/Tenancy/PlatformFeatureEntitlementTest.php`

Expected: FAIL because central ceiling is not consulted.

- [ ] **Step 3: Implement feature gate and platform controls**

Central queries use `central`; tenant preferences use `tenant`. Fail closed if central shop context exists but entitlement lookup fails. Core modules remain enabled. Use checkboxes/toggles with dependency explanations and consistent alignment.

- [ ] **Step 4: Run module regressions**

Run: `php artisan test --compact tests/Feature/Tenancy/PlatformFeatureEntitlementTest.php tests/Feature/ModuleRegistryTest.php`

Expected: PASS.

- [ ] **Step 5: Format and commit**

Commit: `feat: enforce platform shop entitlements`

### Task 10: Audited Read-Only Support Access

**Files:**
- Create: `app/Tenancy/SupportAccessManager.php`
- Create: `app/Tenancy/SupportAccessContext.php`
- Create: `app/Http/Middleware/InitializeSupportAccess.php`
- Create: `app/Http/Middleware/EnforceReadOnlySupportAccess.php`
- Create: `app/Filament/Platform/Resources/ShopAccessSessions/ShopAccessSessionResource.php`
- Create: `resources/views/components/support-access-banner.blade.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Modify: platform shop view actions
- Test: `tests/Feature/Platform/SupportAccessTest.php`

**Interfaces:**
- Produces: `SupportAccessManager::start(PlatformUser $user, Shop $shop, ?string $reason): ShopAccessSession`; `SupportAccessManager::end(): void`; read-only middleware rejects non-GET/HEAD/OPTIONS.
- Consumes: signed support session, tenant resolver, central audit model.

- [ ] **Step 1: Write failing support access tests**

Cover active-shop start, suspended rejection, signed session requirement, audit metadata, visible banner, tenant read access, POST/PATCH/DELETE rejection, exit timestamp, and tenant-user/session separation.

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --compact tests/Feature/Platform/SupportAccessTest.php`

Expected: FAIL because support access does not exist.

- [ ] **Step 3: Implement signed lifecycle, banner, and mutation gate**

Use a dedicated session key containing audit UUID and regenerate the session when entering/exiting. The banner contains shop name, “Read-only support view”, and exit action. Mutation middleware runs before controllers, Livewire updates, and tenant Filament actions.

- [ ] **Step 4: Run support and security tests**

Run: `php artisan test --compact tests/Feature/Platform/SupportAccessTest.php tests/Feature/AuthorizationTest.php`

Expected: PASS; mutation attempts leave tenant database unchanged.

- [ ] **Step 5: Format and commit**

Commit: `feat: add audited read-only shop access`

### Task 11: Tenant Operations Commands and Existing-Database Adoption

**Files:**
- Create: `app/Console/Commands/TenantsMigrateCommand.php`
- Create: `app/Console/Commands/TenantsHealthCommand.php`
- Create: `app/Console/Commands/TenantsAdoptExistingCommand.php`
- Create: `app/Actions/Tenancy/AdoptExistingDatabase.php`
- Test: `tests/Feature/Console/TenantOperationsCommandTest.php`
- Test: `tests/Feature/Console/AdoptExistingTenantTest.php`

**Interfaces:**
- Produces commands `tenants:migrate`, `tenants:health`, and `tenants:adopt-existing` with non-interactive options.
- Consumes: context manager, migration path, health collector, central shop/owner models.

- [ ] **Step 1: Write failing command tests**

Assert migrate one/all, continue-on-error summary, health exit codes, adoption dry-run default, required-table verification, already-adopted rejection, `--force` central registration, owner linking, and no operational copy/drop.

- [ ] **Step 2: Run and verify RED**

Run: `php artisan test --compact tests/Feature/Console/TenantOperationsCommandTest.php tests/Feature/Console/AdoptExistingTenantTest.php`

Expected: FAIL because commands do not exist.

- [ ] **Step 3: Implement commands and adoption action**

All commands accept `--no-interaction`; write operations require `--force` where specified. Iterate active and suspended shops deliberately, isolate each connection in `try/finally`, print per-shop results, and return nonzero when any requested shop fails.

- [ ] **Step 4: Run command tests and list signatures**

Run: `php artisan test --compact tests/Feature/Console/TenantOperationsCommandTest.php tests/Feature/Console/AdoptExistingTenantTest.php`

Run: `php artisan list --format=txt | Select-String 'tenants:'`

Expected: PASS with four tenant commands visible including provisioning.

- [ ] **Step 5: Format and commit**

Commit: `feat: add tenant lifecycle commands`

### Task 12: Isolation Audit, Responsive UI Verification, and Release Gate

**Files:**
- Create: `tests/Feature/Tenancy/CrossTenantIsolationTest.php`
- Create: `tests/Feature/Tenancy/TenantQueueIsolationTest.php`
- Create: `tests/Feature/Tenancy/TenantExportIsolationTest.php`
- Modify: platform and tenant Filament schemas/tables only where browser evidence finds alignment defects
- Modify: `resources/views/pos/create.blade.php` only where tenant/support UI requires it
- Test: all test files

**Interfaces:**
- Consumes: every public tenancy, provisioning, entitlement, support, and UI interface.
- Produces: release evidence; no new architectural API.

- [ ] **Step 1: Write failing adversarial isolation tests**

For two shops with colliding numeric IDs, attempt read/update/delete/export/job authorization across items, sales, expenses, users, vehicles, activity, and invoices. Each assertion must verify both denial and unchanged source data.

- [ ] **Step 2: Run and verify RED where gaps exist**

Run: `php artisan test --compact tests/Feature/Tenancy/CrossTenantIsolationTest.php tests/Feature/Tenancy/TenantQueueIsolationTest.php tests/Feature/Tenancy/TenantExportIsolationTest.php`

Expected: any missing tenant initialization or scoping fails for the exact attack path; if a scenario already passes, retain it only when its production-breaking counterexample is documented in the test.

- [ ] **Step 3: Fix only proven isolation and UI defects**

Add tenant initialization to uncovered jobs/exports/routes and explicit context cleanup. Replace remaining frontend emoji with Heroicons. Use browser measurements to correct only observed desktop layout defects before tablet/mobile adjustments.

- [ ] **Step 4: Browser-verify all primary flows**

At 1440×900, then 768×900, then 390×844 verify:

- Platform login/dashboard/shop list/create/detail/features/support audit
- Tenant login/POS/admin dashboard/users/roles/modules/inventory
- Support banner and exit
- No horizontal overflow, clipped controls, wrapped short labels, unstable cards, nested empty cards, or emoji icons

Record console errors and screenshots/DOM measurements in the task report.

- [ ] **Step 5: Run complete release verification**

Run:

```text
vendor/bin/pint --dirty --format agent
npm run build
php artisan test --compact
git diff --check
```

Expected: formatting and build succeed, full PHPUnit suite passes, browser console is clean, and diff check prints nothing.

- [ ] **Step 6: Commit**

Commit: `test: verify SaaS tenant isolation and interfaces`
