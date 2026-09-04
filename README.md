# Oil Change POS

A multi-tenant SaaS point-of-sale for automotive oil-change and repair workshops. A central control
plane provisions and administers shops; every shop runs its counter, back office and reporting on its
own isolated database.

Two rules shape the whole codebase.

> **Prices are not fixed by inventory.** The salesperson types every charge by hand, per customer,
> per vehicle. Nothing in the database can set or restrict a price.

> **One shop, one database.** No operational table is shared between shops, and no operational model
> can be queried without an initialized tenant context — `App\Models\TenantModel` throws rather than
> silently falling back to the central connection.

## The three surfaces

| Surface | Path | Built with | Who signs in |
|---|---|---|---|
| Platform control plane | `/platform` | Filament panel `platform`, `platform` guard | `PlatformUser` super admins, stored centrally |
| Shop back office | `/admin` | Filament panel `admin`, `web` guard | Shop staff, stored in that shop's database |
| The counter | `/pos`, `/sales`, `/expenses`, … | Blade + Alpine, no build-time framework | Shop staff |

Shop staff sign in at `/login` with a **username**, not an email — usernames are unique inside a shop,
not across the platform. Platform super admins sign in separately at `/platform/login` with an email.
The two guards use different session keys and neither can satisfy the other's authorization checks.

**`/pos` — the counter.** Capture customer and vehicle, add product / repair / free-text lines, type
each price, add labour and misc, check out. A category rail derived from item type and unit of
measure (Oils & fluids, AC gas, Parts, Services) puts the shelf on screen. The **+ New** button next
to any item dropdown opens a quick-add modal that saves to inventory and drops the new item straight
into the current row — without a page reload and without losing a single thing already typed.

**`/admin` — the back office.** Inventory with stock levels, receive-stock actions and low-stock
badges; the vehicle catalogue; staff accounts; built-in and owner-created roles; margin and
consumption reports; the dashboard widgets; and the module switchboard.

**`/platform` — the control plane.** Shops (create, provision, retry, suspend, reactivate, rotate the
database endpoint, set the feature ceiling), per-shop health and statistics, platform users, and the
append-only support-access audit.

## Multi-tenancy

### Two database domains

| Connection | Migrations | Holds |
|---|---|---|
| `central` | `database/migrations/central/` | `platform_users`, `shops`, `shop_owners`, `shop_features`, `shop_access_sessions`, `shop_health_snapshots`, `shop_lifecycle_activities`, `shop_database_target_claims`, plus `sessions`, `cache` and `jobs` |
| `tenant` | `database/migrations/tenant/` | Everything operational: `users`, spatie roles/permissions, `modules`, `items`, `sales`, `sale_items`, `customer_vehicles`, `expenses`, `inspections`, `activity_logs`, `suppliers`, `supplies`, `supplier_payments`, `vehicle_makes`, `vehicle_models`, `item_vehicle_compatibilities`, `tenant_installations` |

There is no default `database/migrations` directory: every migration belongs to one domain or the
other, and `migrate` is always given an explicit `--database` and `--path`.

The `tenant` connection is not configured in `config/database.php` at all. `config('database.tenant_connection_template')`
is a template; `App\Tenancy\TenantConnectionManager` builds the real connection per request from the
`shops` row, purges it, and tears it down afterwards. `TENANT_DB_URL` is deliberately unsupported,
because a URL would override the selected shop.

### Resolving a shop

`App\Tenancy\CentralTenantResolver` reads the raw `Host` header (never Symfony's resolved host, which
honours `X-Forwarded-Host`) and matches `<slug>.<app-url-host>`. In `local` and `testing` the same
resolver also accepts a `/__tenants/{slug}` route prefix, so the whole app is reachable without DNS.
Slugs are validated by `App\Tenancy\TenantSlug` (lowercase DNS label, 63 characters max).

The request then passes, in this order:

1. `InitializeSupportAccess` — resume an audited platform support session, if any.
2. `EnsureShopIsActive` — resolve the shop; anything missing, provisioning, failed or suspended is
   answered with `errors.shop-unavailable` (404 or 503, `Cache-Control: no-store`) and the session is
   invalidated.
3. `InitializeTenancy` — configure and attest the `tenant` connection, bind the session to the shop
   UUID, scope the spatie permission cache key, and namespace Livewire temporary uploads.
4. `EnforceReadOnlySupportAccess` — if a support session is active, refuse anything that is not
   `GET`/`HEAD`/`OPTIONS`.

Middleware priority is pinned explicitly in `bootstrap/app.php`. The session starts first, so a
session or remember cookie belonging to a different shop can be recognised and destroyed rather than
replayed; then the shop is resolved and the tenant connection opened; and only then do panel setup,
authentication, permission checks and `SubstituteBindings` run — no model is ever resolved before the
tenant connection is open. `EnsureCentralHost` sits ahead of cookies and session entirely, so the
platform panel's host check happens before any state exists.

### Database attestation

Every tenant database carries a singleton `tenant_installations` row (`id = 1`) holding the shop UUID,
the target fingerprint and an HMAC derived from `shops.database_attestation_key`. On connect,
`App\Tenancy\TenantDatabaseAttestor` writes a nonce into `connection_nonce`, reads it back over a
freshly opened connection and clears it. A DNS, socket or host change that lands the connection on a
different physical database fails the challenge and the request is answered "shop unavailable" instead
of writing one shop's data into another's. `shops.database_target_fingerprint` and
`shop_database_target_claims.fingerprint` are both unique, so two shops can never register the same
target.

Tenant isolation is also enforced for Livewire (a shop-UUID memo on every component snapshot),
uploads (`EnsureLivewireUploadMatchesTenant`), Filament action routes
(`EnsureFilamentActionMatchesTenant`), queued jobs (`App\Jobs\Concerns\RunsInTenantContext`), and
private files (`App\Tenancy\TenantStoragePath` puts everything under `tenants/{shop-uuid}/`).

### Audited support access

A super admin opens a shop from **Platform → Shops → View shop**, optionally recording a reason. That
writes an immutable `shop_access_sessions` row (operator, shop, start, end, IP, user agent, reason) —
the model refuses updates and deletes outright — and redirects into the shop's `/admin`.

Inside the shop the operator is **not** a tenant `User`. `App\Tenancy\SupportAccessPrincipal` is a
synthetic principal that holds seventeen read permissions and nothing else; `EnforceReadOnlySupportAccess`
rejects every unsafe HTTP method; and an amber "Viewing shop as super admin" banner with an exit
button is rendered into the panel by a Filament render hook. Starting a session ends any other session
the same operator had open, and deactivating, deleting or changing the password of a platform user
ends their live support audits.

Grants have a ceiling (`SUPPORT_ACCESS_MAX_LIFETIME_MINUTES`, default 120). An operator who simply
closes the tab never exits, so `support:sweep-stale-access` runs hourly to close the audit row —
otherwise "who has access to this shop right now" would always answer "everyone who ever looked".

In `local` and `testing` the entry URL is the central host's `/admin`; in production it is
`https://<slug>.<host>/admin`, which is why support access requires `SESSION_DOMAIN` to match the
central application host.

### Shop lifecycle

`shops.status` is one of `provisioning`, `active`, `suspended`, `failed`. Creating a shop in the
platform panel runs `App\Actions\Tenancy\ProvisionShop`, which under a provisioning lock creates the
database, installs the attestation marker, runs the tenant migrations, seeds roles/permissions and
reference data, and creates the first owner user. Every transition is appended to
`shop_lifecycle_activities` (`App\Enums\ShopLifecycleEvent`), and no failure path ever drops a tenant
database — a failed shop is retried, not recreated.

## Modules and the platform feature ceiling

Every feature is an `App\Modules\Module` subclass declaring its key, permissions, navigation,
dependencies and whether it is core. Seven are registered in `ModuleServiceProvider`:

| Key | Title | Core | Depends on |
|---|---|---|---|
| `sales` | Point of Sale & Billing | yes | `inventory` |
| `inventory` | Inventory & Stock | yes | — |
| `admin` | Administration | yes | — |
| `reports` | Reporting Dashboard | no | `sales` |
| `expenses` | Daily Expenses & Cash Flow | no | `sales` |
| `scripts` | Counter Scripts | no | — |
| `workshop` | Workshop Floor | no | — |

Switching a module off from **Admin → Modules** removes its navigation and makes its web routes 404
via the `module:` middleware; Filament resources, pages and widgets check the same registry in their
`canViewAny()` / `canAccess()` / `canView()`, so the back office closes with it. Unknown keys fail
*closed*. Core modules cannot be switched off, and the switchboard refuses to disable a module while
an enabled module still depends on it.

Above the shop's own preference sits a **platform ceiling**. `shop_features` rows, edited from
**Platform → Shops → View shop → Features**, decide which optional modules a shop may use at all;
`App\Tenancy\TenantFeatureGate` denies anything the ceiling refuses, and a failed central lookup
inside a tenant context denies rather than exposes. Disabling a feature never deletes tenant data.

To add a feature: write a `Module` subclass, register it in `ModuleServiceProvider`, and drop a route
file in `routes/modules/`. Nothing else needs editing.

## Roles and permissions

`App\Enums\Permission` is one case per guarded action — 36 of them, grouped into Point of sale, Sales,
Pricing, Inventory, Reporting, Expenses & cash flow, Users, Workshop floor, Administration and Audit.
`App\Enums\Role` bundles them into the three shipped roles, and a tenant migration seeds the spatie
tables from those enums so roles exist in every fresh shop and every `RefreshDatabase` run. **Nothing
checks a role directly** — routes carry `permission:<name>` and Filament gates on the same strings.

| | Admin | Manager | Technician |
|---|---|---|---|
| Ring up a sale | yes | yes | no |
| See prices / totals | yes | yes | no |
| Quick-add inventory | yes | yes | no |
| Delete a sale / item / expense | yes | no | no |
| Unit costs & profit margins | yes | no | no |
| Read the activity log | yes | no | no |
| Expenses & cash drawer | yes | yes | no |
| Counter scripts | yes | yes | yes |
| Vehicle history & inspections | yes | yes | yes |
| Staff accounts & modules | yes | no | no |

Deliberately withheld from Manager: `sales.delete`, `items.delete`, `expenses.delete`,
`items.view_unit_cost`, `items.set_unit_cost`, `reports.view_margins`, `users.*`, `logs.view`,
`modules.manage`, `roles.manage`, `suppliers.manage`. Unit cost is withheld on *every* surface, not
just in Filament: the inventory list column, the item form field, the POS Alpine seed and the
`/quick-items` JSON all gate on `items.view_unit_cost`, and both FormRequests strip `unit_cost` from
the payload entirely when the sender lacks `items.set_unit_cost` — so it can be neither read nor
rewritten. Covered by `UnitCostConfidentialityTest`.

Owners are not limited to the three shipped roles. **Admin → Roles** creates custom roles whose
permission checkboxes are grouped by module and filtered to what the shop is actually entitled to;
the Administrator role is immutable and always holds every registered permission.

## Money

All arithmetic runs through `App\Support\SaleTotalCalculator` in **integer cents**, so `0.1 + 0.2` is
`0.30`. The class is handed nothing but the raw values typed on screen: no database access, no `Item`
import, no notion of a list price. The identical parsing rules are mirrored in the browser, and
`MoneyParityTest` executes the real JavaScript under `node` against the PHP to prove the on-screen
total and the saved invoice cannot drift apart. Amounts accept `1200`, `1200.50` and `1,200.50`;
exponent notation like `1e3` is refused rather than silently billed.

Day boundaries follow `shops.timezone`, not the storage timezone — `App\Support\ShopTimezone` is what
keeps a shop's cash drawer closing on its own midnight.

## Tech stack

PHP packages at the versions installed in this working tree (`composer show --direct`); JavaScript
packages as declared in `package.json`.

| | Version |
|---|---|
| PHP | 8.3 |
| laravel/framework | 13.29 |
| filament/filament | 5.7 |
| spatie/laravel-permission | 8.3 |
| barryvdh/laravel-dompdf | 3.1 |
| phpunit/phpunit | 12.5 |
| laravel/pint | 1.30 |
| tailwindcss | ^4.3 |
| vite | ^8.0 |
| alpinejs | ^3.16 |

Databases: SQLite by default for both domains; MySQL is supported for the central database and for
tenant databases (`App\Tenancy\Provisioning\MySqlDatabaseProvisioner`, including remote provisioning
behind `TENANT_MYSQL_REMOTE_PROVISIONING_ENABLED`).

## Getting it running

```bash
composer setup                # install, .env, key, sqlite file, central migrations, npm build
php artisan platform:make-super-admin
# The command securely prompts for and confirms the password (minimum 12 characters).

composer dev                  # or: npm run dev + php artisan serve
```

`composer setup` runs the central migrations only:

```bash
php artisan migrate --force --database=central --path=database/migrations/central
```

Tenant databases are never migrated by hand at install time — they are created by provisioning.

Then:

1. Sign in at `http://localhost:8000/platform/login`.
2. **Shops → Create shop.** Give it a name, a slug, a timezone and currency, the first owner's name /
   username / temporary password, and tick the optional modules the shop may use.
3. The shop is provisioned and becomes active. In `local` it is reachable at
   `http://localhost:8000/__tenants/<slug>/login`; in production at `https://<slug>.<your-host>/`.

Fresh installs create no default accounts or passwords, and SaaS provisioning never runs
`DatabaseSeeder`. Set `APP_ENV=production` and `APP_DEBUG=false` before exposing the application, and
set `SESSION_DOMAIN` to the central application host — support access refuses to start without it.

If a frontend change does not show up, run `npm run build` (or keep `npm run dev` running).

## Tests

```bash
php artisan test              # or: composer test, vendor/bin/phpunit
vendor/bin/pint               # formatting
```

70 test classes, 1,166 test methods. `phpunit.xml` sets `memory_limit=512M`; the suite runs both
domains on SQLite (`CENTRAL_DB_CONNECTION` and `TENANT_DB_CONNECTION` are pinned to `sqlite`, and
`DB_URL` is blank on purpose so a generic database URL cannot override either target).
`Tests\TestCase` provisions a real central
database and a real per-test tenant database in a temporary directory, registers a `Shop`, connects
the tenant connection, and deletes the files afterwards; `Tests\Concerns\UsesTenantDatabases` keeps a
migrated tenant template so each test copies rather than re-migrates. Tests that need to exercise
resolution itself opt out of the default tenant context and drive the middleware for real.

Built test-first. Where a test passed on the first run, the production code was deliberately mutated
to prove the test actually catches the regression, then restored.

## Artisan commands

| Command | Purpose |
|---|---|
| `platform:make-super-admin` | Create the first active platform super administrator |
| `tenants:provision {shop} [--owner-password-file=] [--force]` | Resume provisioning for one registered shop |
| `tenants:migrate [--shop=] [--force]` | Run pending tenant migrations for eligible shops, recording success or failure per shop |
| `tenants:health [--shop=]` | Refresh `shop_health_snapshots` — connection, migration and seed status |
| `tenants:adopt-existing --name= --slug= --owner-username= [--force]` | Validate (dry run) or adopt the database configured as the application default as a tenant. Without `--force` it never writes |
| `support:sweep-stale-access` | End support audits that outlived the grant ceiling. Scheduled hourly in `routes/console.php` |

`--shop` accepts a shop UUID or an exact slug.

## Where things live

```
app/
  Actions/                      RecordSale, DeleteSale, RecordSupplierPayment,
                                ManageTenantUsers, ManagePlatformUsers
  Actions/Tenancy/              ProvisionShop, AdoptExistingDatabase, CollectShopHealth,
                                CollectTenantStatistics, UpdateShopFeatureEntitlements,
                                RotateShopDatabaseEndpoint, SyncTenantAuthorization, …
  Enums/                        Permission, Role, ItemType, SaleLineType, UnitOfMeasure,
                                PaymentMethod, ExpenseCategory, Inspection*, ShopStatus,
                                ShopLifecycleEvent, DashboardPeriod
  Filament/                     the shop back office (resources, pages, widgets)
  Filament/Platform/            the control plane (Shops, PlatformUsers, ShopAccessSessions)
  Models/Central/               Shop, ShopOwner, ShopFeature, ShopAccessSession, PlatformUser, …
  Models/                       tenant models, all on TenantModel / UsesTenantConnection
  Modules/                      Module base class, ModuleRegistry, Features/*
  Support/                      SaleTotalCalculator, SalesReport, CashDrawer, MarginReport,
                                ConsumptionReport, ServiceHistory, CounterScripts,
                                RolePermissionMatrix, ShopTimezone, AdminDashboardMetrics
  Tenancy/                      resolution, connection management, attestation, provisioning,
                                support access, endpoint rotation
database/migrations/central/    control-plane schema
database/migrations/tenant/     one shop's schema
resources/views/pos/create.blade.php   the counter screen (Alpine cart + quick-add modal)
routes/web.php                  tenant routes, wrapped in the tenancy middleware stack
routes/modules/                 one route file per pluggable feature
```

## Documentation

- [`docs/features/`](docs/features/README.md) — one page per feature area: workflow, code map,
  permissions, invariants and test coverage.
- [`docs/superpowers/specs/2026-09-01-saas-multi-tenant-foundation-design.md`](docs/superpowers/specs/2026-09-01-saas-multi-tenant-foundation-design.md)
  — the multi-tenant design.
- [`docs/superpowers/plans/`](docs/superpowers/plans/) — the implementation plans behind the SaaS
  foundation and vehicle compatibility.
- [`docs/2026-09-03-saas-tasks-1-12-bug-audit.md`](docs/2026-09-03-saas-tasks-1-12-bug-audit.md) —
  the P0/P1 closure report for the SaaS foundation.
- [`PLAN.md`](PLAN.md) — the single-shop build plan and the reasoning behind the hybrid
  Blade/Filament split.
- [`requirement.md`](requirement.md) — the original product requirement document.
- [`AGENTS.md`](AGENTS.md) / [`CLAUDE.md`](CLAUDE.md) — working conventions for this repository.
