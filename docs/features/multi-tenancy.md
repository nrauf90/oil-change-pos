# Multi-tenancy: shops, connections, and isolation

## Purpose and workflow

Every shop is a tenant with its own database. A request names a shop through its host (or, in local
and testing, a route prefix), the shop is looked up in the central database, its status is checked,
and only then is the `tenant` connection configured, attested and opened. Session, authentication,
permission cache, uploads, queued jobs and private files are all bound to that shop for the life of
the request and torn down afterwards. A request that cannot name an active shop never reaches
operational data.

## Data and code map

- Resolution: [`TenantResolver`](../../app/Tenancy/TenantResolver.php), [`CentralTenantResolver`](../../app/Tenancy/CentralTenantResolver.php), [`TenantSlug`](../../app/Tenancy/TenantSlug.php)
- Request pipeline: [`routes/web.php`](../../routes/web.php), [`bootstrap/app.php`](../../bootstrap/app.php), [`EnsureShopIsActive`](../../app/Http/Middleware/EnsureShopIsActive.php), [`InitializeTenancy`](../../app/Http/Middleware/InitializeTenancy.php), [`EnsureCentralHost`](../../app/Http/Middleware/EnsureCentralHost.php), [`ShopUnavailableResponse`](../../app/Http/Responses/ShopUnavailableResponse.php), [`shop-unavailable view`](../../resources/views/errors/shop-unavailable.blade.php)
- Connection lifecycle: [`TenantConnectionManager`](../../app/Tenancy/TenantConnectionManager.php), [`TenantConnectionConfigurationFactory`](../../app/Tenancy/TenantConnectionConfigurationFactory.php), [`TenantContext`](../../app/Tenancy/TenantContext.php), [`TenantRuntimeState`](../../app/Tenancy/TenantRuntimeState.php), [`config/database.php`](../../config/database.php)
- Attestation: [`TenantDatabaseAttestor`](../../app/Tenancy/TenantDatabaseAttestor.php), [`TenantInstallationBootstrapper`](../../app/Tenancy/Provisioning/TenantInstallationBootstrapper.php), [`tenant_installations migration`](../../database/migrations/tenant/2026_09_02_042731_create_tenant_installations_table.php), [`OpenedTenantDatabaseIdentityVerifier`](../../app/Tenancy/OpenedTenantDatabaseIdentityVerifier.php)
- Model contracts: [`TenantModel`](../../app/Models/TenantModel.php), [`UsesTenantConnection`](../../app/Models/Concerns/UsesTenantConnection.php), [`TenantScoped`](../../app/Models/Contracts/TenantScoped.php), [`CentralModel`](../../app/Models/Central/CentralModel.php)
- Session and identity: [`TenantSessionAuthentication`](../../app/Support/TenantSessionAuthentication.php), [`TenantSessionInvalidator`](../../app/Tenancy/TenantSessionInvalidator.php), [`TenantPermissionCache`](../../app/Tenancy/TenantPermissionCache.php), [`config/auth.php`](../../config/auth.php)
- Package and job boundaries: [`TenantPackageRouteRegistrar`](../../app/Tenancy/TenantPackageRouteRegistrar.php), [`EnsureLivewireUploadMatchesTenant`](../../app/Http/Middleware/EnsureLivewireUploadMatchesTenant.php), [`EnsureFilamentActionMatchesTenant`](../../app/Http/Middleware/EnsureFilamentActionMatchesTenant.php), [`TenantLivewireUploadUrlGenerator`](../../app/Tenancy/TenantLivewireUploadUrlGenerator.php), [`RunsInTenantContext`](../../app/Jobs/Concerns/RunsInTenantContext.php), [`InitializeTenantContext`](../../app/Jobs/Middleware/InitializeTenantContext.php), [`TenantStoragePath`](../../app/Tenancy/TenantStoragePath.php)
- Throttling: [`TenantThrottleRequests`](../../app/Http/Middleware/TenantThrottleRequests.php)
- Timezone: [`ShopTimezone`](../../app/Support/ShopTimezone.php)
- Migrations and tooling: [`database/migrations/central`](../../database/migrations/central), [`database/migrations/tenant`](../../database/migrations/tenant), [`TenantMigrationRunner`](../../app/Tenancy/Migrations/TenantMigrationRunner.php), [`TenantsMigrateCommand`](../../app/Console/Commands/TenantsMigrateCommand.php), [`TenantsHealthCommand`](../../app/Console/Commands/TenantsHealthCommand.php)
- Test harness: [`Tests\TestCase`](../../tests/TestCase.php), [`UsesTenantDatabases`](../../tests/Concerns/UsesTenantDatabases.php)
- Tests: [`TenantResolutionTest`](../../tests/Feature/Tenancy/TenantResolutionTest.php), [`TenantConnectionIsolationTest`](../../tests/Feature/Tenancy/TenantConnectionIsolationTest.php), [`CrossTenantIsolationTest`](../../tests/Feature/Tenancy/CrossTenantIsolationTest.php), [`TenantPermissionIsolationTest`](../../tests/Feature/Tenancy/TenantPermissionIsolationTest.php), [`TenantQueueIsolationTest`](../../tests/Feature/Tenancy/TenantQueueIsolationTest.php), [`TenantFileIsolationTest`](../../tests/Feature/Tenancy/TenantFileIsolationTest.php), [`TenantExportIsolationTest`](../../tests/Feature/Tenancy/TenantExportIsolationTest.php), [`TenantDatabaseSessionLifecycleTest`](../../tests/Feature/Tenancy/TenantDatabaseSessionLifecycleTest.php), [`TenantMigrationTest`](../../tests/Feature/Tenancy/TenantMigrationTest.php), [`TenantMigrationHistoryTest`](../../tests/Feature/Tenancy/TenantMigrationHistoryTest.php), [`CentralDomainTest`](../../tests/Feature/Tenancy/CentralDomainTest.php), [`TenantSqliteRootDefaultTest`](../../tests/Feature/Tenancy/TenantSqliteRootDefaultTest.php)

## Two database domains

| Connection | Migration path | Contents |
|---|---|---|
| `central` | `database/migrations/central/` | `platform_users`, `shops`, `shop_owners`, `shop_features`, `shop_access_sessions`, `shop_health_snapshots`, `shop_lifecycle_activities`, `shop_database_target_claims`, plus `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` |
| `tenant` | `database/migrations/tenant/` | `users`, `permissions`, `roles`, `model_has_*`, `role_has_permissions`, `modules`, `items`, `sales`, `sale_items`, `customer_vehicles`, `expenses`, `inspections`, `inspection_items`, `activity_logs`, `suppliers`, `supplies`, `supplier_payments`, `vehicle_makes`, `vehicle_models`, `item_vehicle_compatibilities`, `tenant_installations` |

There is no default `database/migrations` directory. `migrate` is always given an explicit
`--database` and `--path`; tenant databases are migrated by provisioning or by `tenants:migrate`.

The `tenant` connection has no static definition. `config('database.tenant_connection_template')`
supplies the driver and credential shape, and `TenantConnectionManager` writes the real connection
config from the `shops` row before purging and opening it.

## Resolution and the request pipeline

`CentralTenantResolver` reads the raw `HTTP_HOST` header — never `$request->getHost()`, which honours
`X-Forwarded-Host` whenever a proxy is trusted — and takes the label in front of the host of
`config('app.url')` as the slug. In `local` and `testing` it also accepts a `/__tenants/{slug}` route
prefix; when both are present they must match. A support session may supply the shop instead, but only
on the central host and only after `InitializeSupportAccess` has validated it.

Route middleware order, pinned in `bootstrap/app.php`:

1. `InitializeSupportAccess` — resume and validate an audited platform support session.
2. `EnsureShopIsActive` — resolve the shop; missing means 404, non-`active` means 503, and either
   invalidates the session.
3. `InitializeTenancy` — configure, purge, open and attest the tenant connection; bind the session to
   the shop UUID; scope the permission cache; namespace Livewire temporary uploads.
4. `EnforceReadOnlySupportAccess` — reject unsafe methods while a support session is active.

The priority list in `bootstrap/app.php` fixes the rest of the order: `EnsureShopIsActive` runs after
`StartSession` (the session must exist before a foreign-shop session can be recognised and
destroyed), `InitializeTenancy` after `EnsureShopIsActive`, and `EnsureFilamentActionMatchesTenant`
then `SubstituteBindings` after that — so no model is resolved before the tenant connection is open.
`EnforceReadOnlySupportAccess` is placed ahead of panel setup, permission middleware, authentication
and binding substitution. For the platform panel, `EnsureCentralHost` is placed ahead of cookies and
session, so the host check happens before any state exists.

## Permissions and invariants

- Tenant models must extend [`TenantModel`](../../app/Models/TenantModel.php) or use
  [`UsesTenantConnection`](../../app/Models/Concerns/UsesTenantConnection.php). They pin the `tenant`
  connection, refuse `setConnection()` to anything else, and throw when touched from a different shop
  context than the one that created the instance. Central models extend
  [`CentralModel`](../../app/Models/Central/CentralModel.php). No model may silently fall back.
- A shop slug must satisfy `TenantSlug::PATTERN` (lowercase DNS label, 63 characters maximum).
  Registration and adoption reject a slug the resolver could never serve.
- `shops.database_target_fingerprint` and `shop_database_target_claims.fingerprint` are unique, so two
  shops can never register the same physical database. The claim row's foreign key is `RESTRICT`.
- Every tenant database carries a singleton `tenant_installations` row (`id = 1`, unique `shop_id`)
  holding the target fingerprint and an HMAC of `shops.database_attestation_key`. Connecting writes a
  nonce into `connection_nonce`, reads it back over a freshly opened connection, and clears it —
  proving the connection landed on the expected physical database. At rest the nonce must be `NULL`.
- A failed attestation, a missing shop or a non-active shop is answered with
  `errors.shop-unavailable` and `Cache-Control: no-store`. It must never be answered with tenant data.
- Sessions carry `tenant.shop_id`. A session or remember cookie from another shop is discarded and the
  session is regenerated rather than reused.
- The spatie permission cache key is namespaced per shop by `TenantPermissionCache`; leaving a tenant
  context switches to the no-tenant key.
- Livewire component snapshots carry a shop-UUID memo, verified before hydration; uploads, Filament
  action routes, exports, queued jobs and private files are all shop-scoped. Private uploads live
  under `tenants/{shop-uuid}/` on the `local` disk.
- Rate limiting is per shop: `TenantThrottleRequests` is aliased as `throttle`, so one shop cannot
  exhaust another's budget.
- Day boundaries for money that reconciles a day come from `shops.timezone` via `ShopTimezone`, not
  from the storage timezone.
- Provisioning and migration failures must never drop a tenant database.
