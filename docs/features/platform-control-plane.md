# Platform control plane

## Purpose and workflow

Platform super administrators run the SaaS itself from a separate Filament panel at `/platform`, on
the central host only. They register and provision shops, retry failed provisioning, suspend and
reactivate shops, set the feature ceiling each shop operates under, read per-shop health and
statistics, rotate a shop's database endpoint after a DNS move, manage other platform administrators,
and enter a shop through an audited read-only support session. They never become tenant users and
never bypass tenant context initialization.

## Data and code map

- Panel: [`PlatformPanelProvider`](../../app/Providers/Filament/PlatformPanelProvider.php), [`EnsureCentralHost`](../../app/Http/Middleware/EnsureCentralHost.php), [`UsePlatformGuard`](../../app/Http/Middleware/UsePlatformGuard.php), [`EnsureFreshPlatformAuthentication`](../../app/Http/Middleware/EnsureFreshPlatformAuthentication.php), [`PlatformPanelLogoutController`](../../app/Http/Controllers/Auth/PlatformPanelLogoutController.php)
- Identity: [`PlatformUser`](../../app/Models/Central/PlatformUser.php), [`PlatformSessionAuthentication`](../../app/Support/PlatformSessionAuthentication.php), [`ManagePlatformUsers`](../../app/Actions/ManagePlatformUsers.php), [`PlatformMakeSuperAdminCommand`](../../app/Console/Commands/PlatformMakeSuperAdminCommand.php), [`config/auth.php`](../../config/auth.php)
- Shops: [`Shop`](../../app/Models/Central/Shop.php), [`ShopOwner`](../../app/Models/Central/ShopOwner.php), [`ShopStatus`](../../app/Enums/ShopStatus.php), [`ShopResource`](../../app/Filament/Platform/Resources/Shops/ShopResource.php), [`ShopForm`](../../app/Filament/Platform/Resources/Shops/Schemas/ShopForm.php), [`ShopsTable`](../../app/Filament/Platform/Resources/Shops/Tables/ShopsTable.php), [`CreateShop`](../../app/Filament/Platform/Resources/Shops/Pages/CreateShop.php), [`ViewShop`](../../app/Filament/Platform/Resources/Shops/Pages/ViewShop.php)
- Provisioning: [`ProvisionShopData`](../../app/Data/ProvisionShopData.php), [`ProvisionShop`](../../app/Actions/Tenancy/ProvisionShop.php), [`TenantProvisioningLock`](../../app/Tenancy/Provisioning/TenantProvisioningLock.php), [`DatabaseProvisionerManager`](../../app/Tenancy/Provisioning/DatabaseProvisionerManager.php), [`SqliteDatabaseProvisioner`](../../app/Tenancy/Provisioning/SqliteDatabaseProvisioner.php), [`MySqlDatabaseProvisioner`](../../app/Tenancy/Provisioning/MySqlDatabaseProvisioner.php), [`TenantOwnerProvisioner`](../../app/Tenancy/Provisioning/TenantOwnerProvisioner.php), [`SyncTenantAuthorization`](../../app/Actions/Tenancy/SyncTenantAuthorization.php), [`TenantReferenceDataSeeder`](../../database/seeders/TenantReferenceDataSeeder.php)
- Entitlements: [`ShopFeature`](../../app/Models/Central/ShopFeature.php), [`UpdateShopFeatureEntitlements`](../../app/Actions/Tenancy/UpdateShopFeatureEntitlements.php), [`TenantFeatureGate`](../../app/Tenancy/TenantFeatureGate.php)
- Health and statistics: [`ShopHealthSnapshot`](../../app/Models/Central/ShopHealthSnapshot.php), [`CollectShopHealth`](../../app/Actions/Tenancy/CollectShopHealth.php), [`CollectTenantStatistics`](../../app/Actions/Tenancy/CollectTenantStatistics.php), [`ShopStatusOverview`](../../app/Filament/Platform/Widgets/ShopStatusOverview.php), [`PlatformDashboard`](../../app/Filament/Platform/Pages/PlatformDashboard.php), [`DashboardPeriod`](../../app/Enums/DashboardPeriod.php)
- Support access: [`SupportAccessManager`](../../app/Tenancy/SupportAccessManager.php), [`SupportAccessContext`](../../app/Tenancy/SupportAccessContext.php), [`SupportAccessPrincipal`](../../app/Tenancy/SupportAccessPrincipal.php), [`InitializeSupportAccess`](../../app/Http/Middleware/InitializeSupportAccess.php), [`EnforceReadOnlySupportAccess`](../../app/Http/Middleware/EnforceReadOnlySupportAccess.php), [`ShopAccessSession`](../../app/Models/Central/ShopAccessSession.php), [`ShopAccessSessionResource`](../../app/Filament/Platform/Resources/ShopAccessSessions/ShopAccessSessionResource.php), [`support-access-banner`](../../resources/views/components/support-access-banner.blade.php), [`SupportAccessSweepStaleCommand`](../../app/Console/Commands/SupportAccessSweepStaleCommand.php), [`routes/console.php`](../../routes/console.php)
- Endpoint rotation: [`RotateShopDatabaseEndpoint`](../../app/Actions/Tenancy/RotateShopDatabaseEndpoint.php), [`DatabaseEndpointRotationPreviewTokens`](../../app/Tenancy/DatabaseEndpointRotationPreviewTokens.php), [`ReconcileTenantDatabaseEndpointMarker`](../../app/Tenancy/ReconcileTenantDatabaseEndpointMarker.php), [`PublicIpAddressClassifier`](../../app/Tenancy/PublicIpAddressClassifier.php)
- Lifecycle audit: [`ShopLifecycleActivity`](../../app/Models/Central/ShopLifecycleActivity.php), [`ShopLifecycleEvent`](../../app/Enums/ShopLifecycleEvent.php), [`RecordShopLifecycleActivity`](../../app/Actions/Tenancy/RecordShopLifecycleActivity.php)
- Operations commands: [`TenantsProvisionCommand`](../../app/Console/Commands/TenantsProvisionCommand.php), [`TenantsMigrateCommand`](../../app/Console/Commands/TenantsMigrateCommand.php), [`TenantsHealthCommand`](../../app/Console/Commands/TenantsHealthCommand.php), [`TenantsAdoptExistingCommand`](../../app/Console/Commands/TenantsAdoptExistingCommand.php), [`AdoptExistingDatabase`](../../app/Actions/Tenancy/AdoptExistingDatabase.php)
- Tests: [`PlatformAuthenticationTest`](../../tests/Feature/Platform/PlatformAuthenticationTest.php), [`PlatformSessionRevocationTest`](../../tests/Feature/Platform/PlatformSessionRevocationTest.php), [`PlatformMutationSecurityTest`](../../tests/Feature/Platform/PlatformMutationSecurityTest.php), [`PlatformAdministratorConcurrencyTest`](../../tests/Feature/Platform/PlatformAdministratorConcurrencyTest.php), [`PlatformDashboardTest`](../../tests/Feature/Platform/PlatformDashboardTest.php), [`ShopManagementTest`](../../tests/Feature/Platform/ShopManagementTest.php), [`SupportAccessTest`](../../tests/Feature/Platform/SupportAccessTest.php), [`TenantStatisticsTest`](../../tests/Feature/Platform/TenantStatisticsTest.php), [`ShopDatabaseEndpointRotationTest`](../../tests/Feature/Platform/ShopDatabaseEndpointRotationTest.php), [`PlatformFeatureEntitlementConcurrencyTest`](../../tests/Feature/Platform/PlatformFeatureEntitlementConcurrencyTest.php), [`ShopProvisioningTest`](../../tests/Feature/Tenancy/ShopProvisioningTest.php), [`PlatformFeatureEntitlementTest`](../../tests/Feature/Tenancy/PlatformFeatureEntitlementTest.php), [`AdoptExistingTenantTest`](../../tests/Feature/Console/AdoptExistingTenantTest.php), [`TenantOperationsCommandTest`](../../tests/Feature/Console/TenantOperationsCommandTest.php), [`PlatformMakeSuperAdminCommandTest`](../../tests/Feature/Console/PlatformMakeSuperAdminCommandTest.php)

## Panel surfaces

| Screen | Navigation | Purpose |
|---|---|---|
| Platform overview | dashboard | Shop counts by status (Active, Provisioning, Failed, Suspended), each linking to the filtered shop list; a "Create shop" header action |
| Shops | sort 10 | List, create and view shops. There is no edit page and no delete action |
| Support access | sort 80 | Read-only listing of `shop_access_sessions`, newest first, with an "Active" placeholder for sessions that have not ended |
| Platform users | sort 90 | Create, edit and delete super administrators |

Shop record actions are **Suspend** (only while active), **Reactivate** (only while suspended) and
**Retry** (only while failed). The shop detail page adds **View shop** (start a support session),
**Features** (edit the entitlement ceiling) and **Rotate database endpoint** (MySQL targets that use a
hostname rather than a socket).

## Provisioning

Creating a shop captures name, slug, timezone, currency, the first owner's name / username / optional
email / temporary password, and the initial optional features. `ProvisionShop` then, under a central
provisioning lock:

1. Registers the shop and its normalized database target, publishing a `shop_database_target_claim`.
2. Creates the database through the driver's provisioner (SQLite file or MySQL schema).
3. Installs the singleton `tenant_installations` marker before any other schema exists.
4. Runs the tenant migrations, which also seed the roles and permissions from the enums.
5. Seeds reference data (catalogue and vehicle makes/models).
6. Creates the owner user and activates the shop.

Every step is idempotent and resumable: `tenants:provision {shop}` retries from wherever the previous
attempt stopped. `ProvisionShopData` is `#[\SensitiveParameter]`-marked throughout and refuses to be
serialized, so a temporary owner password cannot reach a log, a queue payload or an exception trace.

## Permissions and invariants

- The platform panel has no permission strings. Everything reduces to "an active `PlatformUser` whose
  `role` is `super_admin`", re-verified from the central database on every request by
  `EnsureFreshPlatformAuthentication`, so revocation takes effect on the next request.
- `EnsureCentralHost` compares the raw `HTTP_HOST` header against `config('app.url')` and 404s
  otherwise. It runs before cookies and session start. Host separation is the platform panel's only
  separation, so a trusted proxy's `X-Forwarded-Host` must never be able to move it.
- The `platform` guard and the tenant `web` guard cannot satisfy each other. Logging out of one must
  leave the other's session intact.
- The final active super administrator cannot be deactivated, deleted or demoted, by the panel or by
  a direct model call.
- Deactivating, deleting or changing the password of a platform user ends their active support audits
  and revokes their persisted sessions and remember credentials.
- Shops are created and acted on, never edited or deleted through the panel:
  `ShopResource::canEdit()`, `canDelete()` and `canDeleteAny()` all return `false`. Slug, database
  target and status are not mass assignable and change only through explicit lifecycle transitions.
- `shop_access_sessions` and `shop_lifecycle_activities` are append-only. The session model throws on
  update (except the single end transition) and on delete; a platform user or shop with audit history
  cannot be removed.
- Support access is read-only and audited. It requires an *active* shop, records operator, shop, start,
  end, IP, user agent and optional reason, ends any other session the same operator holds, and expires
  at `auth.support_access.max_lifetime_minutes`. In production it refuses to start unless
  `SESSION_DOMAIN` matches the central application host.
- The feature ceiling is a platform decision that limits, but never replaces, the shop's own module
  preference. Core modules are never listed. Submissions are validated for unknown keys, duplicates
  and unmet dependencies before anything is written, the write is serialized by a central database
  lock, and disabling a feature preserves the tenant data that feature owns.
- Health and statistics collection connects to the tenant, reads, and always disconnects — including
  on failure — restoring the platform guard afterwards. An unreachable shop yields an "unavailable"
  snapshot rather than leaking connection details.
- Endpoint rotation is two-phase: a preview resolves and normalizes the candidate target, and the
  confirmation requires typing the exact shop slug plus a preview token bound to the actor, session,
  shop and resolved addresses. Only permitted public unicast addresses are accepted. An interrupted
  rotation is recovered from the marker, never guessed.
- No provisioning, migration or rollback path may drop a tenant database.
- Safe error codes only: provisioning and rotation failures surface a stage and an uppercase error
  code, never a host, credential or path.
