# Oil Change POS — Build Plan & Architecture

**Stack:** Laravel 13 · Blade + Alpine.js (counter) · Filament 5 (back office and platform panel) ·
Tailwind 4 · SQLite or MySQL · spatie/laravel-permission (RBAC) · barryvdh/laravel-dompdf (invoices)
· PHPUnit (TDD)

This document is the **single-shop** build plan: the product rule, the hybrid Blade/Filament split,
the operational schema, and the module and permission engines. Those decisions survived the move to
SaaS unchanged. The multi-tenant layer that was added on top — central control plane, one database
per shop, provisioning, entitlements and audited support access — is specified in
[`docs/superpowers/specs/2026-09-01-saas-multi-tenant-foundation-design.md`](docs/superpowers/specs/2026-09-01-saas-multi-tenant-foundation-design.md)
and documented in [`docs/features/multi-tenancy.md`](docs/features/multi-tenancy.md) and
[`docs/features/platform-control-plane.md`](docs/features/platform-control-plane.md).

## The one rule the product exists for

> The final price is **always** whatever the salesperson typed on screen right now.
> `items.unit_cost` is a purchasing note — it must never flow into a bill.

Enforced by `App\Support\SaleTotalCalculator`, which is handed nothing but the raw values from the
checkout form: no database access, no `Item` import, no constructor. `App\Actions\RecordSale` reads
only `$item->id` and `$item->name` from inventory. Locked in by tests in `CheckoutTest`,
`DashboardTest`, `InvoiceAndHistoryTest` and `MarginReportTest`.

`MarginReport` is the single place `unit_cost` is read, and it only looks *backwards* at sales that
already happened.

## Why hybrid rather than all-Filament

Filament owns the back office (inventory, staff, expenses, margins, module switchboard) because
CRUD tables are a solved problem. The **counter screen is deliberately not Filament**: typing a price
must not cost a network round-trip on shop wifi. `/pos` is one Alpine component holding the whole
cart, so the quick-add modal never re-renders the form.

## Schema

One shop's operational schema, in `database/migrations/tenant/`. Nothing here is shared between
shops; the control-plane tables live in `database/migrations/central/`.

| table | columns |
|---|---|
| `users` | id, name, **username** (unique within the shop), password, is_active, last_login_at, timestamps |
| `items` | id, name, type(`product`\|`repair`), is_universal, **unit_of_measure**(`piece`\|`litre`\|`kilogram`), pack_label, units_per_pack, measure_per_unit, **unit_cost**, **stock_level**, **low_stock_alert**, is_active |
| `sales` | id, **cashier_id**, invoice_number, customer_name, phone, vehicle_model, vehicle_plate, mileage, next_checkup_mileage, labor_charge, misc_charge, total_amount, notes |
| `sale_items` | id, sale_id, item_id (nullable), item_name (snapshot), type(`product`\|`repair`\|`custom`), **quantity**, **dispensed_quantity**, manually_charged_price |
| `customer_vehicles` | id, customer_name, phone, vehicle_model, vehicle_plate (unique), mileage |
| `expenses` | id, user_id, supplier_payment_id (unique, nullable), category, payment_method, amount, description, spent_at |
| `suppliers` | id, name, contact_person, phone, email, address, notes |
| `supplies` | id, supplier_id, received_at, reference_number, items_received, total_amount, bill_image_path, notes |
| `supplier_payments` | id, supply_id, user_id, amount, method, paid_at, reference_number, receipt_image_path, notes |
| `inspections` | id, sale_id, inspected_by, customer_name, phone, vehicle_plate, vehicle_model, mileage, notes, inspected_at |
| `inspection_items` | id, inspection_id, point, status, note, position |
| `vehicle_makes` | id, name (unique) |
| `vehicle_models` | id, vehicle_make_id, name (unique within a make) |
| `item_vehicle_compatibilities` | id, item_id, vehicle_model_id, year_from, year_to |
| `activity_logs` | id, user_id, user_name (snapshot), action, subject_type, subject_id, description, properties, created_at |
| `modules` | id, key (unique), enabled |
| `tenant_installations` | id (always 1), shop_id (unique), target_fingerprint, attestation_hmac, connection_nonce |
| spatie | roles (+ description), permissions, model_has_roles, model_has_permissions, role_has_permissions |

Notes: `sale_items.item_id` is `nullOnDelete` and `item_name` is snapshotted, so deleting inventory
never rewrites a printed invoice. **`quantity` does not multiply price** —
`manually_charged_price` is the hand-typed line total; quantity exists to drive stock deduction and
print "× 4". `dispensed_quantity` is the same idea for measured stock: 2.5 litres of oil draws 2.5
litres off the shelf and still bills exactly what was typed. `tenant_installations` is not
operational data — it is the singleton identity marker that proves this database belongs to this
shop.

## Modularity

Every feature is an `App\Modules\Module` subclass declaring its key, permissions, navigation and
dependencies. `ModuleRegistry` answers "is this on?", backed by the `modules` table and toggled from
**Admin → Modules**. Disabling a module drops its nav links and 404s its web routes via the `module:`
middleware; Filament resources and pages check the same registry in their
`canViewAny()` / `canAccess()`, so the back office closes with it. Unknown keys fail *closed*. Core modules (sales, inventory, admin) cannot be switched off.

Above the shop's own preference sits the platform entitlement ceiling: `ShopFeature` rows in the
central database, consulted through `App\Tenancy\TenantFeatureGate`. A shop cannot switch on a module
the platform has withheld, and a failed central lookup inside a tenant context denies rather than
exposes.

Adding a feature = one Module class + one line in `ModuleServiceProvider` + a file in `routes/modules/`.

## Authorization

`App\Enums\Permission` is one case per guarded action (36 of them). `App\Enums\Role` bundles them
into Admin / Manager / Technician, and a **migration** seeds the spatie tables from those enums, so
roles exist in every provisioned shop and every `RefreshDatabase` run. Nothing checks a role directly
— routes carry `permission:<name>`, and Filament resources gate
`canViewAny/canCreate/canEdit/canDelete`. Owners may also create additional roles from
**Admin → Roles**, whose permission options are scoped to the modules the shop is entitled to.

Deliberately withheld from Manager: `sales.delete`, `items.delete`, `expenses.delete`,
`items.view_unit_cost`, `items.set_unit_cost`, `reports.view_margins`, `users.*`, `logs.view`,
`modules.manage`, `roles.manage`, `suppliers.manage`. Unit cost is withheld on **every** surface, not just in Filament: the
inventory list column, the item form field, the POS Alpine seed and the `/quick-items` JSON
all gate on `items.view_unit_cost`, and both FormRequests strip `unit_cost` from the payload
entirely when the sender lacks `items.set_unit_cost` — so it can be neither read nor rewritten.
Covered by `UnitCostConfidentialityTest`.
Technician holds only the five workshop-floor permissions — no pricing, no cash, no billing.

## Test suites

| suite | covers |
|---|---|
| `SaleTotalCalculatorTest` (unit) | integer-cent money, PHP/JS contract, overflow |
| `MoneyParityTest` | the browser total and the saved invoice cannot diverge |
| `CheckoutTest` | the flexible-invoice engine, tampering, validation |
| `StockManagementTest` | stock deduction, negative stock, repairs carry none |
| `ItemManagementTest`, `QuickAddItemTest` | inventory CRUD, on-the-fly creation |
| `InvoiceAndHistoryTest` | printable invoice, PDF, WhatsApp link, search |
| `DashboardTest`, `MarginReportTest` | reporting from actually-charged values |
| `ExpenseTest`, `CashDrawerTest` | outlays and shift reconciliation |
| `ServiceHistoryTest`, `InspectionTest` | workshop floor, no-pricing rule |
| `AuthenticationTest`, `AuthorizationTest` | login, throttling, the full role matrix |
| `ModuleRegistryTest`, `AdminPanelTest`, `AdminInventoryTest` | plug-and-play, back office |
| `Tenancy\TenantResolutionTest`, `Tenancy\TenantConnectionIsolationTest` | host resolution, connection lifecycle, attestation |
| `Tenancy\CrossTenantIsolationTest`, `Tenancy\TenantPermissionIsolationTest`, `Tenancy\TenantQueueIsolationTest`, `Tenancy\TenantFileIsolationTest`, `Tenancy\TenantExportIsolationTest` | colliding ids in two shops never leak |
| `Tenancy\CentralDomainTest`, `Tenancy\ShopProvisioningTest` | shop identity rules, idempotent provisioning |
| `Platform\PlatformAuthenticationTest`, `Platform\SupportAccessTest` | platform guard, host separation, audited read-only access |

## UI

High-contrast, `text-lg`+ hit targets, `inputmode="decimal"` money fields, sticky total bar, usable
on a 10" tablet with oily hands. Utilities live in `resources/css/app.css` as Tailwind 4 `@utility`
definitions so derived classes can `@apply` them.
