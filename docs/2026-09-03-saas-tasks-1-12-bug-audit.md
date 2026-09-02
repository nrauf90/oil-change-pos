# SaaS Foundation Tasks 1–12 Bug Audit

**Audit date:** 2026-09-03  
**Status:** Completed audit snapshot at the user-requested stop point; P0/P1 findings are prioritized and lower-severity findings are separated.  
**Release verdict:** **Not release-ready.**

## Scope and evidence standard

This audit compares Tasks 1–12 in `docs/superpowers/plans/2026-09-01-saas-multi-tenant-foundation.md` with the approved design, the current task worktrees, focused and full PHPUnit runs, frontend builds, static inspection, and live browser testing.

A finding is listed as confirmed only when supported by at least one of:

- a live browser reproduction;
- a failing test using the production-relevant configuration;
- a direct code path showing the failure or missing security boundary;
- a missing required artifact or integration point from the approved plan.

### Severity

- **P0 — Release blocker:** core availability, authentication, tenant isolation, destructive data risk, or a security boundary is broken.
- **P1 — High:** required functionality is absent or incorrect, data/audit integrity can be lost, or the release gate cannot provide required assurance.
- **P2 — Medium:** significant UX, observability, maintainability, or incomplete edge-case coverage without an immediate critical failure.
- **P3 — Low:** polish or minor consistency issue.

## Executive P0/P1 bug register

| ID | Severity | Area | Summary | Status |
|---|---|---|---|---|
| SAAS-P0-001 | P0 | Tasks 2–4 / authentication | Tenant login and authenticated tenant requests end in HTTP 500 with the documented database session driver. | Open |
| SAAS-P1-001 | P1 | Task 9 / provisioning | Unchecked default-on features are not stored as disabled, so a shop receives features the super admin explicitly left unchecked. | Open |
| SAAS-P1-002 | P1 | Task 9 / reporting | The shop list counts only explicit enabled rows and can under-report effective features. | Open |
| SAAS-P1-003 | P1 | Task 9 / concurrency | Feature changes do not lock the shop/current feature state, allowing stale decisions and duplicate transition audits. | Open |
| SAAS-P1-004 | P1 | Task 10 / support access | Audited read-only support access is not connected to production routes, panels, shop actions, audit UI, or a complete start/end lifecycle. | Open |
| SAAS-P1-005 | P1 | Task 10 / tenant binding | Dirty support-access code does not prove that the audit shop and the resolved tenant are the same shop. | Open |
| SAAS-P1-006 | P1 | Task 10 / session safety | The dirty initializer treats a missing support tuple as invalid support state and would clear an ordinary tenant login if registered globally as written. | Open |
| SAAS-P1-007 | P1 | Task 7 / statistics | The platform exposes only “This month”; today/week periods and shop-timezone boundaries required by the spec are not implemented. | Open |
| SAAS-P1-008 | P1 | Tasks 5 and 7 / operations | Web provisioning errors are not presented with the required actionable safe summary and failed-shop link. | Open |
| SAAS-P1-009 | P1 | Tasks 5, 7, and 11 / audit | Several lifecycle operations record “System” instead of the initiating platform actor. | Open |
| SAAS-P1-010 | P1 | Task 11 / adoption | Adoption accepts slugs longer than the resolver’s maximum, allowing registration of an unreachable tenant. | Open |
| SAAS-P1-011 | P1 | Task 12 / queue isolation | The required tenant queue-isolation implementation/test is absent. | Open |
| SAAS-P1-012 | P1 | Task 12 / file isolation | Supplier bills and payment receipts use global paths instead of paths namespaced by shop UUID. | Open |
| SAAS-P1-013 | P1 | Release integration | No single clean committed branch currently contains all completed Tasks 1–12 and their release evidence. | Open |
| SAAS-P1-014 | P1 | Tasks 1–3 / database availability | Active database-target attestation pins resolved IPs but provides no safe recovery path for legitimate managed-database DNS failover. | Open |
| SAAS-P1-015 | P1 | Task 4 / test fidelity | PHPUnit forces array sessions, so the release suite does not exercise the documented database-session configuration that triggers SAAS-P0-001. | Open |

## Audited branch snapshot

This document records the following state. Generated `storage/framework/tenant-attestation-locks/` files were present in several worktrees and are not product changes.

- Integration line: `feat/saas-multi-tenant` at `7dc7eb4` (tracked files clean).
- Task 9 entitlement line: `00e315e` (tracked files clean).
- Task 10 support line: `26515b5` plus substantial tracked and untracked support-access work; not a clean commit.
- Task 12 isolation line: `d6def9d` plus later uncommitted test edits.
- Task 12 UI line: clean `780d1e6`.
- Live browser runtime: Task 7 worktree at `69d6d0f`, with built Vite assets and controlled audit data.

Because the branches are split and Task 10/12 still contain uncommitted work, this audit is a defect snapshot, not release sign-off.

## P0 details

### SAAS-P0-001 — Database-backed tenant sessions fail after successful authentication

**Affected tasks:** 2, 3, 4, 8, and every authenticated tenant flow  
**Impact:** Tenant login is unusable under the documented production-style `database` session driver. A controller or Livewire mutation can complete before the late session write throws, creating both an availability failure and a duplicate-write/retry risk.

**Live reproduction**

1. Run the application with `SESSION_DRIVER=database` and `SESSION_CONNECTION=central`.
2. Open an active tenant login route.
3. Submit valid owner credentials.
4. Authentication and `last_login_at` update occur.
5. The response ends as HTTP 500 with `App\Tenancy\Exceptions\TenantNotInitialized`.

**Automated reproduction**

Running the real tenant-resolution login test with the database session driver produces the same HTTP 500. The same test passes under PHPUnit’s forced array session driver, which is why the normal suite misses the production failure.

**Root cause**

1. `StartSession` is outside and before `InitializeTenancy` in middleware priority.
2. `InitializeTenancy` executes the request inside `TenantConnectionManager::within()`.
3. `within()` disconnects and clears the cached web user in `finally` before the outer session middleware persists the session.
4. Laravel’s `DatabaseSessionHandler` asks the default guard for its user ID during the final write.
5. The forgotten guard reloads the tenant `User` by ID after tenant context has been cleared.
6. The tenant model correctly fails closed with `TenantNotInitialized`.

**Evidence locations**

- `bootstrap/app.php` — middleware order.
- `app/Http/Middleware/InitializeTenancy.php` — request executes inside the bounded tenant connection.
- `app/Tenancy/TenantConnectionManager.php` — context/connection/guard cleanup in `finally`.
- `app/Models/Concerns/UsesTenantConnection.php` — fail-closed tenant model contract.
- `phpunit.xml` — tests force `SESSION_DRIVER=array`.
- `tests/TestCase.php` — ordinary authentication tests disable tenancy middleware.
- `tests/Feature/Tenancy/TenantResolutionTest.php` — real middleware login path.

**Required fix**

Keep tenant context active until the database session has been persisted, then disconnect. The lifecycle should be:

`resolve shop → initialize connection/context → start session → bind session to shop → authenticate/handle request → persist session → clear tenant state`

This likely requires separating tenant connection lifetime from session-to-shop binding. Do not suppress `TenantNotInitialized`, weaken the tenant model contract, or leave the cached guard user alive across requests.

**Minimum regression tests**

- Successful tenant login redirects and persists using the real database session handler.
- A second request authenticates after application guards are reset.
- Tenant state and the dynamic connection are cleared after each response.
- Logout destroys/rotates only the tenant identity while preserving a platform identity.
- An authenticated tenant POST persists both its domain change and session without a late 500.

## Task-by-task audit

### Task 1 — Central Database and Platform Domain

**Status:** Core central connection/model/guard contracts are implemented. The latest focused Tasks 1–4 review passed 199 of 200 tests with one skipped and 1,271 assertions. Two P1 boundary/availability gaps remain.

#### Shared shop registration accepts resolver-invalid slugs

`Shop` registration paths can accept arbitrary/general-length slugs, while `CentralTenantResolver` only accepts DNS-safe slugs up to 63 characters. This is broader than the adoption-only path described under SAAS-P1-010: a non-UI caller can persist or provision a shop that the canonical request resolver will never open.

**Required fix:** centralize slug normalization and validation in one immutable value/rule used by the `Shop` model boundary, provisioning DTO/action, adoption command, URL generation, and resolver. Reject invalid persisted state rather than relying only on Filament form validation.

#### SAAS-P1-014 — DNS failover has no safe re-attestation path

The active database target fingerprint includes resolved IP addresses and is treated as immutable. This protects against DNS rebinding, but a legitimate managed-database failover that changes the resolved IP causes the tenant connection to be rejected with no operator workflow to approve the new endpoint safely.

**Impact:** an ordinary provider failover can brick an active shop even though hostname, credentials, and intended database remain valid.

**Required fix:** retain fail-closed verification but add an explicit, authenticated, audited re-attestation/rotation workflow. It should show old/new normalized targets, require deliberate confirmation, reject private/forbidden destinations, update the fingerprint atomically, and preserve an audit trail.

### Task 2 — Tenant Context, Dynamic Connection, and Model Contract

**Status:** Core fail-closed model/connection contract exists. P0 session-lifecycle integration is open as SAAS-P0-001.

### Task 3 — Trusted Tenant Resolution and Middleware Ordering

**Status:** Trusted route/host resolution, unavailable states, and context cleanup are implemented and broadly tested. Production database-session ordering is broken as SAAS-P0-001.

### Task 4 — Tenant Migration Layout and Test Harness

**Status:** Central/tenant migration separation exists and focused migration tests pass. The test harness has a P1 fidelity gap and the complete suite needs more memory than the default CLI configuration.

#### SAAS-P1-015 — Production session behavior is excluded from the normal suite

`phpunit.xml` forces `SESSION_DRIVER=array`, while `.env.example` and the documented deployment path use database sessions on the central connection. Ordinary authentication tests also disable tenant middleware. This combination allowed SAAS-P0-001 to remain green in routine CI.

**Required fix:** keep fast array-session tests where appropriate, but add a mandatory database-session feature group that uses the real middleware stack for login, subsequent authenticated requests, mutations, logout, guard coexistence, and cleanup. The release command must execute that group.

### Task 5 — Idempotent Tenant Authorization and Shop Provisioning

**Status:** Core database creation, migrations, seed synchronization, hashed owner creation, idempotent retry, safe failed state, and no-implicit-drop behavior are implemented and focused tests pass. Remaining P1 defects are the unsafe web failure experience (SAAS-P1-008), missing initiating actor in lifecycle audit (SAAS-P1-009), shared slug-boundary inconsistency, and the Task 9 initial-feature handoff (SAAS-P1-001).

No separate P0 was found in the provisioning state machine. Failed databases are deliberately retained, which matches the non-destructive specification.

### Task 6 — Platform Authentication and Filament Panel

**Status:** Super-admin authentication, dashboard access, guard separation, and final-active-super-admin protection pass focused tests and live browser checks. The reported Vite manifest failure was repaired locally by building the locked frontend assets; no dependency change was needed.

### Task 7 — Platform Shop Management, Health, and Statistics

**Status:** Core create/provision/list/view/suspend/reactivate behavior works in the browser. P1 statistics, failure UX, and audit-actor gaps remain.

#### SAAS-P1-007 — Required statistics periods and timezone semantics are absent

The shop detail hard-codes the month period and renders only “This month.” The design requires today, current week, and current month, evaluated in the selected shop’s timezone. Ambient UTC-style `now()` boundaries can report the wrong shop totals near day/week/month edges.

**Required fix:** expose all three periods, calculate boundaries in `Shop::timezone`, convert query bounds correctly for stored timestamps, and add boundary tests around midnight and week/month rollover.

#### SAAS-P1-008 — Provisioning failure UI is not actionable

The web create path allows provisioning exceptions to bubble into a generic server failure instead of showing the required safe stage summary and link to the failed shop. The retry path handles errors more safely, so the two operator paths are inconsistent.

**Required fix:** catch the application-safe provisioning exception at the Filament action boundary, notify with a redacted stage/message, link to the retained failed shop, and test that secrets never appear.

#### SAAS-P1-009 — Initiating actor is lost from lifecycle audit events

Provision/retry and related lifecycle records are emitted as “System” even when initiated by an authenticated platform user. This weakens accountability for security-sensitive control-plane actions.

**Required fix:** pass a stable platform actor descriptor/ID through each lifecycle action and assert the exact actor in central audit records.

### Task 8 — Tenant Owners, Custom Roles, and Permission Cache Isolation

**Status:** Tenant-local roles, single-role assignment, final-administrator/self-lockout protection, registered-permission limits, and tenant-keyed permission-cache resets are implemented and covered by focused tests. No separate P0/P1 defect was confirmed in this task’s core mutation rules. Browser owner authentication and every authenticated authorization flow remain operationally blocked by SAAS-P0-001.

**Residual assurance gap:** platform-disabled permission assignment needs to remain in the integrated Task 9/Task 8 regression set; a passing Task 8 branch alone does not prove the later entitlement ceiling.

### Task 9 — Platform Feature Entitlements

**Status:** Partially complete at clean commit `00e315e`. Missing-row defaults and fresh actor revalidation were hardened. Focused entitlement tests passed, but provisioning and concurrency defects remain.

#### SAAS-P1-001 — Unchecked default-on features remain enabled

`ProvisionShop` writes only selected feature keys as `enabled=true`. It creates no explicit `false` row for an unchecked feature. Because every current optional module defaults on, `TenantFeatureGate` interprets that missing row as enabled.

**Browser evidence:** Scripts was left unchecked while creating the audit shop. The list displayed “3 enabled,” but the later feature form showed all four optional features checked/effectively enabled.

**Required fix:** during provisioning, persist an explicit row for every registered optional module using the submitted true/false state. Add a browser/feature test that creates a shop with one default-on module unchecked and proves navigation and routes are disabled.

#### SAAS-P1-002 — Feature count disagrees with effective entitlements

The shop resource counts explicit `enabled=true` rows, while effective state includes missing default-on rows. This produced the observed “3 enabled” count even though four features were effectively enabled.

**Required fix:** make provisioning explicit as above and calculate list state through the same effective-entitlement contract, not a raw relation count.

#### SAAS-P1-003 — Feature transitions are not serialized by shop/state

The update action locks and revalidates the platform actor, but it does not lock the shop or existing `shop_features` state before computing transitions. Two super admins can read the same old state and emit stale or duplicate audit decisions.

**Required fix:** lock the shop row and deterministically lock/read current feature rows inside one transaction; add a real two-connection/two-actor concurrency test. The current transaction-begin test is not real concurrency and accepts an unrelated Livewire snapshot exception.

### Task 10 — Audited Read-Only Support Access

**Status:** Not complete. The latest support branch contains committed prerequisite merges plus dirty/untracked scaffolding. Passing tests use synthetic routes and do not prove production integration.

#### SAAS-P1-004 — Required support flow is not wired

At the audited snapshot, the implementation lacks a complete platform start action, production middleware/panel wiring, end/exit lifecycle, support audit resource, and proven persistent banner across tenant admin and POS layouts.

**Required fix:** complete the signed start/end state machine, register the middleware before tenant auth and all Livewire/Filament mutations, add shop action/audit resource/banner/exit UI, rotate the session on both transitions, and exercise real production routes.

#### SAAS-P1-005 — Support audit is not bound to the resolved tenant

The manager validates the audit’s recorded shop but does not establish, at the production resolution boundary, that it equals the tenant opened by host/route. Without this invariant, a support audit for shop A could be combined with a local-prefixed route for shop B.

**Required fix:** make signed support selection an input to the canonical resolver and compare audit shop, selected shop, and resolved shop before tenant initialization. Reject and invalidate any mismatch.

#### SAAS-P1-006 — Ordinary tenant sessions would be cleared

The dirty support manager treats absence of the support tuple as invalid support state and clears normal tenant login/session state. If the initializer is installed globally/persistently as written, normal tenant users would be logged out on ordinary requests.

**Required fix:** distinguish “no support session” from “present but invalid support session.” No support tuple must be a no-op; only a malformed, stale, replayed, or mismatched tuple should invalidate support state.

**Additional P1 gaps**

- No complete explicit `end()`/`ended_at` lifecycle; abandoned audits can remain open.
- Concurrent starts are not serialized or constrained against overlapping active audits.
- Production cookie-domain/session transport for platform-to-tenant support selection is not demonstrated.
- The temporary principal presents as tenant Admin/Owner, exposing mutation UI even when unsafe methods are blocked later.
- Required tests for real tenant reads, unchanged tenant data after Livewire/Filament mutations, suspension/deactivation mid-session, replay/stale selectors, banner, exit, and database-backed sessions are absent.

### Task 11 — Tenant Operations Commands and Existing-Database Adoption

**Status:** Provision/migrate/health/adopt commands, dry-run-first adoption, required-table checks, non-interactive options, per-shop cleanup, and bootstrap corrections are present. The earlier Task 9 branch had one stale migration-order test assertion; the integration bootstrap-fix line corrected that expectation. The remaining confirmed P1 is the shared slug boundary below.

#### SAAS-P1-010 — Adoption can register an unreachable slug

Adoption accepts a slug up to the general database/string maximum while the production tenant resolver rejects slugs over 63 characters. The command can therefore create a central shop record that no request can resolve.

**Required fix:** share one slug value object/rule between provisioning, adoption, route generation, and resolution; add an exact 63/64-character boundary test.

### Task 12 — Isolation Audit, Responsive UI Verification, and Release Gate

**Status:** Not complete. Cross-tenant and invoice-export tests are committed separately, the required queue isolation test is absent, and no clean branch contains Task 10 plus the Task 12 release gate.

#### SAAS-P1-011 — Queue isolation is absent

There is no `TenantQueueIsolationTest.php` proving shop identity transport, initialization before model loading, cleanup after each job, colliding IDs, or cross-job leakage. The exact Task 12 plan command fails because the file is missing.

**Required fix:** introduce/verify the tenant-aware job lifecycle and tests for sequential jobs from different shops in one worker process, including exception cleanup.

#### SAAS-P1-012 — Tenant uploads are not namespaced

Supplier bills use a shared `supplier-bills/` path and supplier payment receipts use a shared `supplier-payment-receipts/` path. The design requires tenant-generated files and exports to use paths scoped by shop UUID.

**Required fix:** use a centralized tenant storage-path service rooted at the immutable shop UUID, migrate/read legacy paths safely, and add collision/authorization tests.

#### SAAS-P1-013 — No integrated release candidate exists

The implementation is distributed across several branches/worktrees. Task 9 and UI improvements are clean commits, Task 10 remains dirty, and Task 12 isolation work is on a separate base. Passing a suite on one branch therefore does not validate the code intended to ship.

**Required fix:** integrate Tasks 1–12 into one release-candidate branch, resolve conflicts without dropping bootstrap fixes, then rerun every task command plus the full release gate on that exact commit.

## Live browser audit

### Passed Super Admin flows

- Missing Vite assets rebuilt successfully; `public/build/manifest.json` exists.
- Active Super Admin login and `/platform` dashboard.
- Shop creation with owner, timezone, currency, and initial feature selection.
- Tenant database provisioning, migrations, seed state, owner creation, and selected-shop statistics.
- Shop search/filter, detail view, suspension confirmation, branded suspended-shop rejection, and reactivation.
- Platform-user list and final-active-super-admin self-deactivation protection.
- Exact platform viewport checks at 1440×900, 768×900, and 390×844 for overview, shops, and platform users: main content rendered and document width matched viewport width with no horizontal overflow.

### Failed or incomplete browser flows

- Valid tenant owner login returns HTTP 500 under database sessions (SAAS-P0-001), blocking POS/admin browser coverage.
- Unchecked Scripts entitlement remained effectively enabled (SAAS-P1-001) while the list reported only three features (SAAS-P1-002).
- Immediately after reactivation, Livewire-rendered health/statistics temporarily showed “Unavailable”; a hard/detail navigation recovered healthy values. This is tracked as a P2 stale-state/refresh defect pending a focused reproduction test.
- Task 10 support entry, persistent banner, mutation rejection, exit, and audit resource cannot be browser-verified because the production flow is not complete.

## Verification evidence

- Latest integrated Tasks 1–4 focused review: 199/200 tests passed, one skipped, 1,271 assertions.
- Frontend production build: passed; Vite manifest generated.
- Audited full Task 9-era suite with 512 MB: 1,064 tests; 1,062 passed, 1 failed, 1 skipped; 5,152 assertions. The failure was a stale migration-order assertion on that older branch, not the production sort behavior.
- Task 9 latest focused entitlement suite: 51/51 passed, 266 assertions.
- Task 9 provisioning suite: 29/29 passed, 214 assertions.
- Dirty Task 10 focused suite at audited snapshot: 50/50 passed, 314 assertions, but only against incomplete/synthetic integration.
- UI/Heroicon focused suite: 162/162 passed, 928 assertions.
- Existing central tenant-resolution/permission isolation suite: 82/82 passed, 640 assertions.
- Composer locked dependency audit: no known advisories.
- PHP syntax audit: 247/247 checked files passed at the audited snapshot.
- The default 128 MB CLI limit is insufficient for the full suite; 512 MB was needed to obtain a complete result.
- A new consolidated Tasks 1–11 run was started on the clean UI/integration line but was interrupted at the user’s request before it produced a result; it is deliberately not reported as passing.

## Lower-severity backlog

These items remain important but must not displace P0/P1 work:

- **P2:** Shop detail uses narrow cards on desktop and wraps short values despite available horizontal space.
- **P2:** Health collection collapses broad failures into generic “Unavailable” instead of distinct connection, migration, seed, and application categories.
- **P2:** Several frontend close/remove controls still render `×` glyphs rather than Heroicons.
- **P2:** Below the `xl` breakpoint, the frontend brand link can lose its accessible name because its text is hidden and its icon is `aria-hidden`.
- **P2:** The POS icon component renders multiple complete SVGs and hides unused variants, causing avoidable DOM growth in repeated rows.
- **P2:** The current platform feature default parameter remains easy for a future caller to omit incorrectly.
- **P2:** Platform detail/list transient state can show health/statistics as unavailable until a full navigation refreshes the snapshot.

## Required fix order

1. Fix SAAS-P0-001 and add real database-session middleware regression coverage.
2. Complete and secure Task 10 before exposing any support route or UI.
3. Fix Task 9 provisioning semantics/counting and serialize feature transitions.
4. Close Task 7 statistics/timezone, operator error UX, and actor-audit gaps.
5. Add Task 12 queue and tenant-file isolation coverage.
6. Integrate all task branches into one clean release candidate.
7. Run Pint, production frontend build, the complete PHPUnit suite, `git diff --check`, and the full platform/tenant/support browser matrix on that exact commit.
