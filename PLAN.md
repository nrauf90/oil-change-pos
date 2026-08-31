# Oil Change POS — Build Plan & Architecture

**Stack:** Laravel 13 · Blade + Alpine.js (counter) · Filament 5 (back office) · Tailwind 4 · SQLite
· spatie/laravel-permission (RBAC) · barryvdh/laravel-dompdf (invoices) · PHPUnit (TDD)

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

| table | columns |
|---|---|
| `users` | id, name, **username**, password, is_active, last_login_at, timestamps |
| `items` | id, name, type(`product`\|`repair`), **unit_cost**, **stock_level**, **low_stock_alert**, is_active |
| `sales` | id, invoice_number, **cashier_id**, customer_name, phone, vehicle_model, vehicle_plate, mileage, labor_charge, misc_charge, total_amount, notes |
| `sale_items` | id, sale_id, item_id (nullable), item_name (snapshot), type(`product`\|`repair`\|`custom`), **quantity**, manually_charged_price |
| `expenses` | id, user_id, category, amount, description, spent_at |
| `inspections` | id, sale_id, inspected_by, customer_name, phone, vehicle_plate, vehicle_model, mileage, notes, inspected_at |
| `inspection_items` | id, inspection_id, point, status, note, position |
| `modules` | id, key, enabled |
| spatie | roles, permissions, model_has_roles, role_has_permissions |

Notes: `sale_items.item_id` is `nullOnDelete` and `item_name` is snapshotted, so deleting inventory
never rewrites a printed invoice. **`quantity` does not multiply price** —
`manually_charged_price` is the hand-typed line total; quantity exists to drive stock deduction and
print "× 4".

## Modularity

Every feature is an `App\Modules\Module` subclass declaring its key, permissions, navigation and
dependencies. `ModuleRegistry` answers "is this on?", backed by the `modules` table and toggled from
**Admin → Modules**. Disabling a module drops its nav links and 404s its web routes via the `module:`
middleware; Filament resources and pages check the same registry in their
`canViewAny()` / `canAccess()`, so the back office closes with it. Unknown keys fail *closed*. Core modules (sales, inventory, admin) cannot be switched off.

Adding a feature = one Module class + one line in `ModuleServiceProvider` + a file in `routes/modules/`.

## Authorization

`App\Enums\Permission` is one case per guarded action (35 of them). `App\Enums\Role` bundles them
into Admin / Manager / Technician, and a **migration** seeds the spatie tables from those enums, so
roles exist in every `RefreshDatabase` run. Nothing checks a role directly — routes carry
`permission:<name>`, and Filament resources gate `canViewAny/canCreate/canEdit/canDelete`.

Deliberately withheld from Manager: `sales.delete`, `items.delete`, `expenses.delete`,
`items.view_unit_cost`, `items.set_unit_cost`, `reports.view_margins`, `users.*`, `logs.view`,
`modules.manage`. Unit cost is withheld on **every** surface, not just in Filament: the
inventory list column, the item form field, the POS Alpine seed and the `/quick-items` JSON
all gate on `items.view_unit_cost`, and both FormRequests strip `unit_cost` from the payload
entirely when the sender lacks `items.set_unit_cost` — so it can be neither read nor rewritten.
Covered by `UnitCostConfidentialityTest`.
Technician holds only the four workshop-floor permissions — no pricing, no cash, no billing.

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

## UI

High-contrast, `text-lg`+ hit targets, `inputmode="decimal"` money fields, sticky total bar, usable
on a 10" tablet with oily hands. Utilities live in `resources/css/app.css` as Tailwind 4 `@utility`
definitions so derived classes can `@apply` them.
