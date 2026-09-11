# Sale screen: custom-line placement, wider product wall, discount instead of labor/misc

## Spec (user request, verbatim intent)

> on sales screen move custom line button beside or above item list where we show added items. make
> items view little wider reduce items list page area width. add discount field and remove misc and
> labour cost.

No spec file exists; this plan is the binding spec.

## Clarifications obtained before planning (binding)

"Remove misc and labour cost" touches far more than the sale screen — `labor_charge`/`misc_charge`
are columns on both `sales` and `orders`, and are read by the invoice PDF, the sale show page,
service history, the sales report, and the activity log (13 app files, 11 test files reference them).
Asked the user how far to go; answers, binding for this plan:

1. **UI-only removal.** Stop collecting Labor/Misc on the POS ticket and add a Discount field in
   their place. Do **not** drop the `labor_charge`/`misc_charge` columns or touch historical data —
   existing sales keep whatever values they already have. New sales/orders simply never populate
   them again (they default to `0` at the DB level already). No destructive migration anywhere in
   this plan.
2. **Discount is a flat amount**, not a percentage — the counter types e.g. "200" meaning "200 off
   the bill", the same kind of typed-money field Labor/Misc already were.
3. **Applies to both flows** — direct checkout (`Sale`) and held/draft orders (`Order`) — the two
   already mirror each other for `labor_charge`/`misc_charge` and must keep mirroring each other for
   `discount`.

## Current-state findings (already verified by research, do not re-derive)

- The whole sale screen is one file: `resources/views/pos/create.blade.php` (Blade + a large Alpine
  `posCounter()` component in a `@push('scripts')` block). Layout: **A** customer/vehicle strip,
  **B1** category rail (left, `w-52`/`xl:w-56`), **B2** product wall (`flex-1`, the tile grid), **B3**
  the ticket (`aside`, currently `lg:w-[23rem] xl:w-[26rem]`).
- The "Custom line" button is currently in **B1**, the category rail, alongside "Low stock" and
  "Counter scripts" (around line 245 of the current file — `@click="addCustomLine()"`). It must move
  to **B3**, the ticket pane, not be duplicated in both places.
- Money math exists in two places that must never disagree, enforced by
  `tests/Feature/MoneyParityTest.php`: **`App\Support\SaleTotalCalculator`** (PHP, the stored/printed
  total) and the `normalise`/`toCents`/`money` trio inside `posCounter()`'s Alpine object (JS, the
  on-screen running total). **This plan does not touch `normalise`, `toCents`, or `money` in either
  language** — only the `totalCents` *getter* (JS) and `SaleTotalCalculator::total()`'s *call sites*
  change. `MoneyParityTest` stays green untouched as long as those three functions are byte-for-byte
  unchanged.
- `SaleTotalCalculator::total(iterable $prices, mixed $laborCharge, mixed $miscCharge): string` is
  called from far more places than checkout: `app/Actions/RecordSale.php` (the real checkout),
  **and also** `app/Filament/Widgets/TodayFinancialStats.php`, `app/Support/AdminDashboardMetrics.php`,
  `app/Support/MarginReport.php` (×2) — these reuse it as a generic "sum plus/minus two amounts"
  helper for dashboard/margin arithmetic, always passing `null` for the misc slot. **A new `$discount`
  parameter must be optional and default to `null`, appended after `$miscCharge`**, so none of those
  four unrelated call sites (or `tests/Unit/SaleTotalCalculatorTest.php`'s existing cases) need to
  change. Only `RecordSale.php` passes a real discount.
- `Sale` and `Order` models both already have `labor_charge`/`misc_charge` in `$fillable` and cast
  `decimal:2`; both tables have the columns as `decimal(12, 2)->default(0)` (not nullable). `discount`
  follows the exact same shape on both.
- `CompleteOrder::saleData()` is the bridge: it copies an `Order`'s fields into the array
  `RecordSale` consumes, so a completed draft produces an identical `Sale` to one rung up directly.
  `discount` must be added to that bridge the same way `labor_charge`/`misc_charge` already are.
- `StoreSaleRequest`/`DraftOrderRequest` validate `labor_charge`/`misc_charge` identically:
  `['nullable', 'regex:'.SaleTotalCalculator::PATTERN, 'numeric', 'min:0', 'max:99999999']`, normalise
  them via `SaleTotalCalculator::normalise()`/`::amount()` in `prepareForValidation()`/`orderPayload()`.
  `discount` gets the identical rule and normalisation — it is validated as a **positive** typed
  amount (the counter never types a minus sign for a discount; the *subtraction* happens in the total
  formula, not in the sign of what was typed).
- `SaleObserver` logs `labor_charge`/`misc_charge` into the activity-log audit trail on sale
  create/delete (two spots). `discount` is a real new money field on the same model and belongs in
  that same audit trail — add it alongside, same pattern, both spots.
- Three views print a Labor/Miscellaneous breakdown next to the total, all in the identical
  two-line shape: `resources/views/sales/pdf.blade.php` (~line 90), `resources/views/sales/show.blade.php`
  (~line 88), `resources/views/service-history/index.blade.php` (~line 137). Because historical sales
  keep real `labor_charge`/`misc_charge` values while every new sale will have `0` for both and a real
  `discount` instead, these three views must show Labor/Misc **only when non-zero** and a new Discount
  line **only when non-zero** — otherwise every new invoice prints two permanent "0.00" lines and the
  discount is invisible, which would make the printed total look wrong to whoever reads it.
- `SalesReport`, `AdminDashboardMetrics`'s cost/margin math, and `ServiceHistory` (the PHP support
  classes, not the views above) also reference `labor_charge`/`misc_charge`, but only to sum/display
  them — since new rows simply carry `0` for both, no code change is needed there. **Do not touch
  these files** — they are correct already, out of scope.

## Global Constraints (binding on every task)

1. **No destructive migrations.** Only additive `Schema::table(...)->decimal('discount', 12, 2)->default(0)`
   on `sales` and `orders`. Never touch, rename, or drop `labor_charge`/`misc_charge`.
2. **`SaleTotalCalculator::total()`'s new `$discount` parameter is optional, defaults to `null`, and
   is the 4th positional parameter** (after `$miscCharge`). Formula becomes
   `sumToCents($prices) + toCents($laborCharge) + toCents($miscCharge) - toCents($discount)`. No
   clamping to zero — consistent with the class's existing philosophy that a typed amount is real even
   if the arithmetic goes negative (see its own docblock: "negative lines are discounts" already
   describes today's line-level behavior; this is the same idea at the bill level). Do not add a
   floor/clamp that isn't asked for.
3. **Do not touch** `normalise()`/`toCents()`/`money()` in `SaleTotalCalculator` or in the Alpine
   component — `MoneyParityTest` pins these character-for-character and this plan has no reason to
   change what counts as a valid typed amount.
4. **Do not touch** `SalesReport`, `AdminDashboardMetrics`, `MarginReport`, `ServiceHistory` (the PHP
   support/report classes) — only their existing `labor_charge`/`misc_charge` reads, which need no
   change per the findings above. Do not touch `TodayFinancialStats.php`'s `SaleTotalCalculator::total()`
   call — it must keep working unchanged because the new parameter defaults to `null`.
5. **Validation rule shape for `discount`** on both `StoreSaleRequest` and `DraftOrderRequest`, verbatim:
   `['nullable', 'regex:'.SaleTotalCalculator::PATTERN, 'numeric', 'min:0', 'max:99999999']` — same
   shape as `labor_charge`/`misc_charge`, i.e. always a non-negative typed amount.
6. **Naming, verbatim:** column `discount` (not `discount_amount`, not `discount_charge`) on both
   `sales` and `orders`; request field name `discount`; Alpine state key `discount` (replacing `labor`
   and `misc`); JS getter stays named `totalCents` (no rename).
7. Follow existing conventions: PHP 8 typed properties/returns, curly braces always, PHPDoc over
   inline comments. Run `vendor/bin/pint --dirty --format agent` after any PHP change. Every task
   updates or adds tests and runs them. Read `testing-best-practices` for guidance on test shape.
8. This plan does not touch permissions, routes, or any other module. `pricing.view`,
   `draft_sales.*`, `sales.*` permissions are unaffected — discount is entered by whoever can already
   use the sale screen or save a draft, exactly like Labor/Misc were.

## Task 1 — Backend: the `discount` field (schema, calculator, requests, actions, audit log)

**Files**: two new tenant migrations (add-column, one per table — reuse today's date, pick timestamps
after the latest existing tenant migration); `app/Support/SaleTotalCalculator.php`;
`app/Models/Sale.php`; `app/Models/Order.php`; `app/Http/Requests/StoreSaleRequest.php`;
`app/Http/Requests/DraftOrderRequest.php`; `app/Actions/RecordSale.php`; `app/Actions/CompleteOrder.php`;
`app/Observers/SaleObserver.php`; new/extended test files.

**Requirements**:
- Migration 1: `Schema::table('sales', ...)` adds `$table->decimal('discount', 12, 2)->default(0)->after('misc_charge');`.
  Migration 2: same shape on `orders`. `down()` drops the column on each. Follow the style of
  `database/migrations/tenant/2026_08_27_000102_add_stock_columns_to_items_table.php` (anonymous
  class, typed `up()`/`down()`).
- `SaleTotalCalculator::total()`: add the optional `mixed $discount = null` parameter (Global
  Constraint 2) and the subtraction in the formula. Update its docblock's one-line description
  ("Final payable amount: manual lines + manual labor + manual misc.") to mention the discount is
  subtracted. Do not touch any other method.
- `Sale::$fillable` / `Order::$fillable`: add `'discount'`. `casts()` on both: add
  `'discount' => 'decimal:2'`. Follow the exact placement style already used for `labor_charge`/`misc_charge`
  in each file.
- `StoreSaleRequest::rules()` / `DraftOrderRequest::rules()`: add the `discount` rule (Global
  Constraint 5), placed next to the existing `labor_charge`/`misc_charge` rules. `StoreSaleRequest::prepareForValidation()`:
  add `'discount' => $this->money($this->input('discount'))` to the merged array, same pattern as
  `labor_charge`. `DraftOrderRequest::orderPayload()`: add
  `$data['discount'] = SaleTotalCalculator::amount($data['discount'] ?? null);`, same pattern as
  `labor_charge`. Add a `discount.regex` message to `StoreSaleRequest::messages()` matching the
  existing `labor_charge.regex`/`misc_charge.regex` messages' shape.
- `RecordSale::__invoke()`: add `'discount' => SaleTotalCalculator::amount($data['discount'] ?? null)`
  to the `new Sale([...])` array, and pass `$data['discount'] ?? null` as the 4th argument to
  `SaleTotalCalculator::total(...)`.
- `CompleteOrder::saleData()`: add `'discount' => $order->discount` to the returned array, next to
  `'labor_charge'`/`'misc_charge'`.
- `SaleObserver`: add `'discount' => $sale->discount` to both places it currently logs `labor_charge`/
  `misc_charge` (create and delete).
- Check `app/Http/Controllers/OrderController.php` for any place it reads/writes `labor_charge`/
  `misc_charge` directly (rather than going through `DraftOrderRequest::orderPayload()`) — if none,
  no change needed there; note this either way in the report.

**Tests**: `SaleTotalCalculator::total()` with a discount subtracts correctly and a call with the
4-arg form omitted still works exactly as before (extend `tests/Unit/SaleTotalCalculatorTest.php`,
following its existing case shapes). A sale created via `sales.store` with a `discount` persists it
and `total_amount` reflects the subtraction (extend `tests/Feature/CheckoutTest.php` or
`tests/Feature/SaleRecordTest.php` — check which already covers labor/misc and match its style). A
draft order with a discount, when completed via `CompleteOrder`, produces a `Sale` with the same
`discount` (extend whatever test already covers `CompleteOrder` — check `tests/Feature/` for existing
draft-completion coverage first). The activity log captures `discount` on a sale (extend
`tests/Feature/ActivityLogTest.php`'s existing labor/misc coverage if it has any, else add a focused
case). Existing `tests/Feature/DashboardTest.php`, `tests/Unit/SaleTotalCalculatorTest.php`'s current
cases, and `TodayFinancialStats`/`MarginReport` tests must keep passing unchanged — run them to
confirm the optional 4th parameter didn't disturb the 2-arg-style call sites.

## Task 2 — Sale screen: discount input, drop Labor/Misc, relocate Custom line, narrow the ticket

**Depends on Task 1** (the form will post a `discount` field the backend must already understand).

**Files**: `resources/views/pos/create.blade.php` only.

**Requirements**:
- **Discount replaces Labor/Misc in the ticket's add-ons block** (currently the two
  `<div class="flex h-9 items-center justify-between gap-3">` rows for `labor_charge`/`misc_charge`,
  just above "Total payable"): delete both rows, add one row in their place —
  `<label for="discount">Discount</label>` + `<input id="discount" name="discount" type="text" inputmode="decimal" class="pos-input-money w-32" placeholder="0.00" x-model="discount">` —
  same input styling class (`pos-input-money`) the removed fields used.
- **Alpine config/state**: in the `x-data="posCounter(@js([...]))"` call, replace
  `'labor' => (string) old('labor_charge', ($draft ?? null)?->labor_charge ?? ''),` and the `misc`
  line with `'discount' => (string) old('discount', ($draft ?? null)?->discount ?? ''),`. In
  `window.posCounter`'s returned object, replace `labor: config.labor, misc: config.misc,` with
  `discount: config.discount,`.
- **`totalCents` getter**: change from
  `this.lineSubtotalCents + this.toCents(this.labor) + this.toCents(this.misc)` to
  `this.lineSubtotalCents - this.toCents(this.discount)`. Do not touch `toCents`, `normalise`, or
  `money` themselves (Global Constraint 3) — this is the only line in the money section that changes.
- **Relocate "Custom line"**: remove the `<button type="button" class="pos-rail-btn" @click="addCustomLine()">…Custom line</button>`
  block from the category rail (B1, the `<nav aria-label="Product categories">` section). Add it to
  the ticket pane (B3, the `<aside aria-label="Current ticket">`), as a full-width slim button placed
  directly *above* the scrollable ticket-lines `<div class="pos-scroll divide-y divide-slate-100">`
  (between that div and the `pos-pane-head` header above it) — i.e. above the list of added items,
  matching the request. Give it a compatible look: a left-aligned icon (`heroicon-o-pencil-square`,
  the same one the rail button used) + "+ Custom line" text, full width, a bottom border to separate
  it from the lines below (e.g. `class="flex w-full items-center gap-2 border-b border-slate-200 px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-50"`
  — adjust to match the file's existing utility-class conventions, don't invent a new visual language).
  Keep the `@click="addCustomLine()"` handler unchanged — only the button's location and markup shell
  move, not its behavior.
- **Narrow the ticket / widen the product wall**: change the ticket `<aside>`'s width classes from
  `lg:w-[23rem] xl:w-[26rem]` to `lg:w-80 xl:w-[22rem]` (20rem / 22rem). Because the product wall
  section is `flex-1` in the same flex row, narrowing the ticket's fixed width automatically widens
  the product wall — no change needed to the product wall's own classes. Leave the category rail
  (`w-52`/`xl:w-56`) untouched — the request is about the product wall versus the ticket, not the
  rail.

**Tests**: extend or add a Feature test for `pos.create` (check `PosCategoryRailTest.php` and any
existing sale-screen rendering test for the established style) asserting: the rendered page contains
a `name="discount"` input and does **not** contain `name="labor_charge"` or `name="misc_charge"`
inputs; the Alpine config JSON contains `"discount"` and not `"labor"`/`"misc"` keys; the "Custom
line" button appears once (not duplicated) and its markup now sits ahead of the ticket-lines
container rather than inside the category rail nav (a `assertSeeInOrder`-style check, or a string-position
comparison on the rendered HTML, is enough — don't over-engineer this into a browser test). Also
confirm `MoneyParityTest` still passes untouched (it re-extracts `normalise`/`toCents`/`money` from
the rendered page — this task must not have touched those three functions, so it should need no
changes at all; run it to prove that).

## Task 3 — Display: conditional Labor/Misc/Discount lines on invoices, sale view, and service history

**Depends on Task 1** (the `discount` column must exist to display it) — independent of Task 2's file
(different views), can run any time after Task 1.

**Files**: `resources/views/sales/pdf.blade.php`, `resources/views/sales/show.blade.php`,
`resources/views/service-history/index.blade.php`.

**Requirements**: in each of the three files, at the existing Labor/Misc breakdown block:
- Wrap the existing "Labor" row in a non-zero check (`@if ((float) $sale->labor_charge > 0)` /
  equivalent for the loop variable name each file actually uses — `$sale` in the PDF/show views,
  `$visit` in service history) and likewise for "Miscellaneous". A historical sale that has real
  labor/misc keeps showing them exactly as today; a new sale (always `0` for both) shows neither row.
  Do not delete these rows — condition them.
- Add a "Discount" row, same visual shape as the row(s) it sits beside in each file, shown only when
  `(float) $sale->discount > 0` (or `$visit->discount`), formatted the same way
  (`number_format((float) $sale->discount, 2)`).
- Keep the total line itself completely unchanged in all three files — it already prints
  `total_amount`, which already correctly reflects the discount because `RecordSale` computed it via
  `SaleTotalCalculator::total()` in Task 1. This task is purely about the breakdown lines matching
  what the total implies, nothing about totals math.

**Tests**: a sale with a positive `discount` and zero labor/misc shows a "Discount" line and no
"Labor"/"Miscellaneous" lines on the PDF and the show page (extend whatever test already renders
these — check `tests/Feature/InvoiceAndHistoryTest.php` first, it's the most likely existing owner of
this coverage). A sale with historical non-zero `labor_charge`/`misc_charge` and zero `discount` (the
old shape) still shows Labor/Misc and no Discount line, proving backward compatibility with existing
data. Service history: a visit with a discount shows it (extend `tests/Feature/ServiceHistoryTest.php`
if it covers this rendering, else add a focused case matching its existing style).

## Verification

Each task runs its own tests plus `vendor/bin/pint --dirty --format agent`. Final whole-branch review
runs `php artisan test --compact` in full, including `MoneyParityTest`, `SaleTotalCalculatorTest`,
`CheckoutTest`, `DashboardTest`, and any margin/report tests that call `SaleTotalCalculator::total()`
— these are the exact tests that would catch a signature or formula mistake in Task 1.
