# SaaS Multi-Tenant POS Foundation Design

## Objective

Convert the existing single-shop Laravel POS into a SaaS foundation with a central super-admin control plane and one isolated operational database per shop. A super admin can provision and suspend shops, create their first owner, control feature availability, inspect shop health and statistics, and enter a shop through an audited support session. Shop owners continue to manage their own staff, roles, permissions, inventory, sales, expenses, vehicles, and reports.

## Scope

This foundation includes tenant isolation, provisioning, authentication boundaries, shop administration, tenant-aware roles and permissions, feature entitlements, audited support access, per-shop statistics, migration tooling, adoption of the current database, tests, and responsive interfaces.

This foundation does not include billing, subscriptions, custom-domain automation, DNS management, automated backups, or managed production database-server provisioning. The domain model and service boundaries must allow those capabilities to be added later without changing tenant identity or moving operational records back into the central database.

## Architecture Decision

Use Laravel's native database manager and dynamically configured connections instead of introducing a tenancy package. Laravel 13 and Filament 5 are already installed; avoiding an additional tenancy abstraction reduces compatibility risk and keeps database selection explicit and testable.

The application has two database domains:

1. The **central database** stores platform identities, shops, provisioning state, database descriptors, feature entitlements, support-session audits, and cached shop health summaries.
2. Each **tenant database** stores one shop's operational POS data and its local users, roles, permissions, module preferences, and activity log.

No operational model may silently fall back to the central connection. Tenant models use a dedicated tenant base model or explicit tenant connection contract. Central models use a central base model or explicit central connection contract. A request that needs tenant data must have an initialized tenant context before authentication or model queries occur.

## Identity and Authentication

### Platform identities

`PlatformUser` is stored centrally and authenticates through a dedicated `platform` guard. The initial platform role is `super_admin`; the schema allows future platform roles without coupling them to tenant permissions. Platform users access a separate Filament panel at `/platform`.

### Tenant identities

The existing `User` model remains tenant-local. This preserves direct foreign keys such as `sales.cashier_id` and prevents cross-database relationships. Each shop receives an owner user during provisioning. Owners create and manage tenant staff and roles through the existing admin panel.

Tenant usernames are unique inside a shop, not globally. Tenant sign-in resolves the shop before credentials are queried. Central and tenant guards use different session keys and cannot satisfy each other's authorization checks.

### Support access

A super admin may open an audited support session for an active shop. The support session records platform user, shop, start time, end time, source IP, user agent, and optional reason. The UI must display a persistent, high-contrast “Viewing shop as super admin” banner with the shop name and an exit action.

Support access is read-only by default. Any future write-capable support mode must be a separate explicit capability and is outside this foundation. Super admins never become tenant `User` records and never bypass tenant context initialization.

## Tenant Resolution and Request Lifecycle

Production tenant routing uses the shop slug as a subdomain. Local development and automated tests may resolve the same slug from a route prefix or signed session selector. Both mechanisms call the same `TenantResolver` interface.

The request lifecycle is:

1. Resolve the central `Shop` record from the trusted host, route value, or signed support session.
2. Reject missing, unknown, provisioning, failed, or suspended shops before tenant authentication.
3. Configure and purge the named `tenant` database connection.
4. Bind an immutable `TenantContext` containing the shop identity for the request.
5. Prefix tenant-sensitive cache keys and Spatie permission cache state with the shop identifier.
6. Continue to tenant authentication, authorization, controllers, Livewire, and Filament.
7. Clear tenant connection and scoped state at request termination and between queued jobs.

Tenant selection cannot come from an unsigned query string or arbitrary request input. Queue jobs that touch tenant data carry a shop identifier and initialize context inside the job before loading tenant models.

## Central Data Model

### `platform_users`

- `id`
- `name`
- `email`, unique
- `password`
- `is_active`
- `last_login_at`
- timestamps

### `shops`

- UUID primary key
- `name`
- `slug`, unique and immutable after activation in this foundation
- `status`: `provisioning`, `active`, `suspended`, or `failed`
- `database_driver`: `mysql` or `sqlite`
- `database_name`
- optional encrypted connection overrides for host, port, username, and password
- `timezone`, default `Asia/Karachi`
- `currency`, default `PKR`
- provisioning failure message and timestamps
- timestamps and soft deletion

Production deployments should normally use server-level database credentials from environment configuration and store only the per-shop database name. Per-shop credentials, when used, must be encrypted casts and never rendered in tables, logs, notifications, or exception messages.

### `shop_owners`

Stores the central provisioning/contact identity for each shop owner: shop UUID, name, login username, optional email, active flag, and timestamps. Tenant credentials are created in the tenant database; plaintext passwords are never retained centrally.

### `shop_features`

Stores shop UUID, registered module key, enabled flag, and timestamps. A missing row uses the module's platform default. Core modules remain enabled. Platform entitlements form a ceiling over the tenant's existing `modules` preference: effective enabled state is `core OR (platform_enabled AND tenant_enabled)`.

### `shop_access_sessions`

Stores the immutable audit data for each super-admin support session. Ending a session sets `ended_at`; audit rows are never edited or deleted through the UI.

### `shop_health_snapshots`

Stores non-sensitive cached summaries such as last successful connection, migration status, last activity, counts, and monetary totals. These records support the platform list without connecting to every tenant database during a single page render.

## Provisioning

`ProvisionShop` is an application service with an idempotent state machine:

1. Validate and reserve the central shop slug.
2. Create the central shop in `provisioning` state.
3. Create the tenant database or SQLite file through a database provisioner abstraction.
4. Configure the tenant connection.
5. Run tenant migrations from `database/migrations/tenant`.
6. Seed registered permissions, roles, modules, catalogue defaults, and other required tenant data.
7. Create the tenant owner with a hashed temporary password.
8. Mark the shop active and record a healthy snapshot.

Failures mark the shop `failed`, store a safe operator-facing message, and preserve enough state for retry. Retry resumes idempotently and must not create duplicate owners, roles, or seed data. Database deletion is not an automatic rollback because it is destructive. A separate explicit cleanup command may remove a failed empty database after operator confirmation.

The web action dispatches provisioning synchronously for the first implementation so completion and failures are deterministic. The service boundary permits moving it to a queue later.

## Migration Layout and Existing Data

New central migrations live in `database/migrations/central`. Tenant migrations live in `database/migrations/tenant`. Existing operational migrations are treated as tenant migrations. The implementation must preserve normal test database setup while introducing explicit commands:

- `tenants:provision {shop}`
- `tenants:migrate {--shop=} {--force}`
- `tenants:health {--shop=}`
- `tenants:adopt-existing`

`tenants:adopt-existing` registers the current database as the first shop without copying or deleting operational records. It performs a dry-run by default, verifies required tenant tables, rejects an already adopted database, and requires `--force` to write central records. It creates or links the owner without exposing a stored password. No migration command automatically drops a database.

## Platform Panel

The `/platform` Filament panel contains:

- Dashboard cards for active, provisioning, failed, and suspended shops
- Shops table with status, owner, features, health, last activity, and actions
- Create-shop workflow collecting shop identity, owner identity, temporary password, timezone, currency, and initial features
- Shop detail page with health, migration state, recent statistics, feature entitlements, suspend/reactivate action, retry provisioning, and audited support access
- Platform-user management restricted to active super admins
- Support-access audit table

Statistics are read from one selected tenant at a time through a `TenantStatistics` service. The first version reports sales, expenses, gross margin, transaction count, active users, inventory count, and last activity for today, current week, and current month. The service never performs cross-database joins. Platform list pages use health snapshots; opening a shop detail may refresh that shop's snapshot.

## Tenant Panel and Authorization

The existing admin panel remains the shop panel. Tenant owners receive the administrator role and can:

- Create, edit, deactivate, and delete eligible staff accounts
- Create and edit custom tenant roles
- Assign registered permissions limited to platform-enabled modules
- Manage tenant module preferences without overriding platform-disabled features

Owners cannot remove their own last administrative access, deactivate themselves, or delete the final active owner/administrator. Existing lockout protections remain and are extended to custom roles. Suspended shops cannot authenticate or access POS routes.

Spatie role and permission tables live in each tenant database. Permission caching must be tenant-keyed and reset when tenant context changes. Platform authorization does not use tenant Spatie roles.

## Feature Entitlements

The existing module catalogue remains the source of known module keys, dependencies, navigation, and permissions. `ModuleRegistry::enabled()` evaluates both central entitlement and tenant preference. Unknown modules fail closed. Disabling a platform feature immediately hides navigation and returns 404 for its routes. Dependency validation prevents states where an enabled module depends on a disabled feature.

Feature changes are audited centrally. Enabling a feature may seed newly registered tenant permissions idempotently. Disabling a feature never deletes tenant data.

## Security and Isolation Requirements

- Every operational query executes on the active tenant connection.
- Central queries remain available without an active tenant.
- Tenant middleware executes before session authentication that loads tenant users.
- Route model binding cannot resolve tenant models before tenant initialization.
- Cache, permission cache, rate-limit, and queued-job keys include the shop UUID where tenant data is involved.
- Validation uniqueness checks run against the correct connection.
- Files generated for invoices or exports use tenant-scoped paths.
- Logs include shop UUID but exclude database credentials and passwords.
- Platform support access is audited and visually unmistakable.
- Tests must prove that IDs from one tenant cannot be read, updated, deleted, exported, or authorized from another tenant.

## Uniform UI/UX Requirements

Both Filament panels and the frontend POS must use the established amber primary color, Heroicons, typography, field heights, card radii, borders, and spacing. Emoji are not used as interface icons.

Desktop layouts are designed and browser-verified first, followed by tablet and mobile. Forms use explicit responsive grids rather than auto-flow or masonry placement. Related cards remain in stable rows when conditional content appears. Inputs and adjacent actions share the same baseline and 36-pixel control height. Tables use consistent row density, aligned action columns, meaningful empty states, and no nested empty cards.

Platform status uses consistent badges: active green, provisioning amber, suspended gray, and failed red. Destructive actions require confirmation and explain impact. Support mode uses a persistent banner in both admin and POS layouts. Every platform and tenant page must be checked at 1440×900, 768×900, and 390×844 without horizontal overflow, clipped actions, wrapped short button labels, or unexpected card movement.

## Error Handling and Observability

Tenant resolution errors return a branded unavailable page without exposing connection details. Provisioning and migration errors are logged with shop UUID and a safe stage name. Platform notifications show actionable summaries and link to the failed shop. Health checks distinguish connection, migration, seed, and application failures.

All lifecycle operations produce structured activity records. Database provisioning, suspension, reactivation, feature changes, migration, support entry, and support exit are auditable.

## Testing Strategy

Implementation follows test-driven development. Each production behavior begins with a focused failing PHPUnit test, followed by minimal implementation and regression verification.

The suite includes:

- Central and tenant model connection contracts
- Tenant resolution from trusted hosts, routes, and signed support sessions
- Unknown and suspended tenant rejection
- Authentication guard separation
- Two-tenant CRUD and authorization isolation for every major operational area
- Tenant-keyed Spatie permission caching
- Provisioning success, safe failure, and idempotent retry
- Owner and role management lockout protection
- Platform entitlement enforcement over tenant module preferences
- Support-session audit and read-only enforcement
- Per-shop statistics correctness
- Existing-database adoption dry-run and idempotency
- Tenant migration and health commands
- Filament panel authorization and resource behavior
- Browser verification of platform, tenant admin, and POS layouts at required viewport sizes

MySQL is the production target. Automated tests use isolated temporary SQLite central and tenant databases where possible, plus database-driver contract tests for MySQL-specific provisioning SQL without executing destructive server operations.

## Delivery Sequence

1. Establish central and tenant connection contracts and migration separation.
2. Implement tenant context, resolver, middleware, cache isolation, and tests.
3. Add platform identities, guard, and Filament panel.
4. Implement idempotent shop provisioning and owner creation.
5. Add shop management, suspension, health, and statistics.
6. Extend tenant owner, staff, custom-role, and lockout behavior.
7. Enforce platform feature entitlements over tenant module preferences.
8. Implement audited read-only support access.
9. Add migration, health, and existing-database adoption commands.
10. Complete isolation, security, responsive browser, and whole-branch reviews.

Each sequence item must remain independently testable and pass task-level specification and code-quality review before dependent work begins.

## Acceptance Criteria

- A super admin can sign in to `/platform`, create a shop and owner, and observe successful database provisioning.
- Two shops use different databases and cannot access each other's data under any tested route, model, Livewire action, export, or job.
- An owner can sign in only within their shop and manage eligible users and roles without risking final-admin lockout.
- A super admin can suspend or reactivate a shop and control its feature ceiling.
- A super admin can view accurate selected-shop statistics and enter or exit an audited read-only support session.
- Existing operational data can be adopted as the first tenant through a safe dry-run-first command.
- Central and all tenant migrations can be applied through explicit commands without destructive implicit cleanup.
- Platform, tenant admin, and POS interfaces satisfy the uniform responsive UI requirements.
- Focused tests, the full PHPUnit suite, Pint, frontend build, browser console checks, and final code review pass before completion is claimed.
