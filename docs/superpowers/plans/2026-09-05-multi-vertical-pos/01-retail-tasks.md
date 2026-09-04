# Retail (Kirana and Super Store) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a working general-store POS, and in doing so build the price book, tax, barcodes, variants and the customer ledger that four other trades wait on.

**Architecture:** Core gains a sell price, tax-inclusive arithmetic, barcodes, variants and an append-only customer ledger. The retail pack adds a scan-led screen and a department tree.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 5, PHPUnit 12, Blade, Alpine.js.

**Spec:** [`price-book.md`](../../specs/2026-09-05-multi-vertical-pos/price-book.md), [`customer-accounts-and-ledger.md`](../../specs/2026-09-05-multi-vertical-pos/customer-accounts-and-ledger.md), [`pack-retail.md`](../../specs/2026-09-05-multi-vertical-pos/pack-retail.md)

**Depends on:** [Foundation](00-foundation-tasks.md) complete.

---

## Brainstorm

**What a kirana counter actually does.** Scans, scans, scans, takes cash, moves on — often with a queue behind. Anything that steals focus from the barcode field is a defect. A super store is the same counter with more departments and more items; it is not a separate vertical.

**Decisions taken without asking:**

- **Barcode field is always focused.** Every other control is reachable without leaving it. This single behaviour is what makes or breaks the screen.
- **An unknown barcode offers to create the item inline** rather than dead-ending. A cashier holding a queue cannot go to an admin screen.
- **Weight is typed, not read from a scale.** Scale integration is hardware work; typing covers the common case and is what most shops do today.
- **Udhaar is a first-class button**, not buried. Credit is how a large share of Pakistani kirana trade works, and a shop that cannot record it will not adopt the system.
- **Quick-sell tiles** for the twenty or thirty items sold constantly without a barcode — loose sugar, milk, bread, single cigarettes.
- **A held bill** so a customer who forgot something steps aside without losing the cart.
- **Return and exchange** are included. They are daily, not exceptional.
- **Rounding to the nearest rupee** at the total, recorded as its own line so takings reconcile.
- Prices are **tax-inclusive**; the receipt decomposes tax out rather than adding it on.

**Deliberately excluded:** loyalty points, promotions engine, multi-branch stock transfer, scale integration, supplier auto-ordering. Each is a real feature and none is needed to sell the first shop.

## Global Constraints

- Tax-inclusive arithmetic: derive per line, round once at the line, then sum. The customer-facing total never moves.
- The ledger is append-only. Corrections are compensating entries.
- A historical sale never recomputes from a current price.
- No task in this file may reference a pack from core code.

---

## Module: Price book

### Task 1: CORE — tax classes CRUD, part 1: list

**Files:**
- Create: `database/migrations/tenant/*_create_tax_classes_table.php`, `app/Models/TaxClass.php`, `database/factories/TaxClassFactory.php`
- Create: `app/Http/Controllers/TaxClassController.php`, `resources/views/tax-classes/index.blade.php`
- Modify: `routes/modules/admin.php`
- Test: `tests/Feature/TaxClassTest.php`

- [ ] **Step 1: Generate through Artisan**
- [ ] **Step 2: Write failing tests** — `test_the_tax_class_list_loads_with_an_empty_state`, `test_the_list_shows_each_class_with_its_rate`
- [ ] **Step 3: Verify red**
- [ ] **Step 4: Implement** — columns `name`, `rate` decimal(5,4), `is_inclusive` default true
- [ ] **Step 5: Verify green and format**

### Task 2: CORE — tax classes, part 2: create

- [ ] **Step 1: Write failing tests** — `test_a_tax_class_is_created`, `test_the_rate_must_be_between_zero_and_one`, `test_the_name_is_required`
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** `create`/`store` with a form request
- [ ] **Step 4: Verify green and format**

### Task 3: CORE — tax classes, part 3: update

- [ ] **Step 1: Write failing tests** — `test_a_tax_class_is_updated`, `test_changing_a_rate_does_not_alter_past_sales`
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

### Task 4: CORE — tax classes, part 4: delete

- [ ] **Step 1: Write failing tests** — `test_an_unused_tax_class_is_deleted`, `test_a_tax_class_in_use_cannot_be_deleted`
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — refuse rather than cascade
- [ ] **Step 4: Verify green and format**

### Task 5: CORE — sell price on items

**Files:**
- Create: `database/migrations/tenant/*_add_sell_price_and_tax_class_to_items_table.php`
- Modify: `app/Models/Item.php`, `database/factories/ItemFactory.php`
- Test: `tests/Feature/PriceBookTest.php`

- [ ] **Step 1: Write failing tests** — `test_an_item_stores_a_sell_price_and_tax_class`, `test_an_item_without_a_sell_price_is_valid`
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — both nullable; `manual` product types still price by hand
- [ ] **Step 4: Verify green and format**

### Task 6: CORE — tax-inclusive calculator

**Files:**
- Create: `app/Support/InclusiveTax.php`
- Test: `tests/Unit/InclusiveTaxTest.php`

**Interfaces:**
- Produces: `InclusiveTax::decompose(string $gross, string $rate): array{net: string, tax: string}`

- [ ] **Step 1: Write failing tests**

```php
public function test_it_derives_net_and_tax_from_a_gross_price(): void;
public function test_it_rounds_once_at_the_line(): void;
public function test_a_zero_rate_yields_zero_tax(): void;
public function test_summed_line_tax_matches_the_invoice_total(): void;
```

The fourth is the one that catches the classic one-rupee discrepancy. Do not skip it.

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — `net = gross / (1 + rate)`, rounded at the line
- [ ] **Step 4: Verify green and format**

### Task 7: CORE — `PriceResolver` strategy contract

**Files:**
- Create: `app/Pricing/PriceResolver.php`, `app/Pricing/FixedPriceResolver.php`, `app/Pricing/ManualPriceResolver.php`
- Test: `tests/Feature/Pricing/PriceResolverTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_a_fixed_type_resolves_the_item_sell_price(): void;
public function test_a_manual_type_resolves_the_typed_price(): void;
public function test_the_resolver_is_selected_by_product_type_not_by_shop(): void;
```

The third is the design's core claim. Assert it explicitly.

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — resolve from the container keyed by `pricing_mode`
- [ ] **Step 4: Verify green and format**

### Task 8: CORE — snapshot price and tax on sale lines

**Files:**
- Create: `database/migrations/tenant/*_add_price_snapshot_to_sale_items_table.php`
- Modify: `app/Actions/RecordSale.php`, `app/Models/SaleItem.php`
- Test: `tests/Feature/PriceBookTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_a_sale_line_snapshots_unit_price_tax_rate_and_tax_amount(): void;
public function test_changing_an_item_price_later_does_not_alter_a_past_sale(): void;
```

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

### Task 9: CORE — price change log

**Files:**
- Create: `database/migrations/tenant/*_create_price_changes_table.php`, `app/Models/PriceChange.php`
- Modify: `app/Observers/ItemObserver.php` (or create it)
- Test: `tests/Feature/PriceChangeTest.php`

- [ ] **Step 1: Write failing tests** — `test_changing_a_sell_price_records_who_and_when`, `test_the_log_is_append_only`
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

### Task 10: CORE — permissioned price override at the counter

**Files:**
- Modify: `app/Enums/Permission.php`, `app/Http/Requests/StoreSaleRequest.php`, `app/Actions/RecordSale.php`
- Test: `tests/Feature/PriceOverrideTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_a_cashier_without_permission_cannot_override_a_fixed_price(): void;
public function test_an_override_is_recorded_in_the_activity_log(): void;
```

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

---

## Module: Barcodes

### Task 11: CORE — barcode storage

**Files:**
- Create: `database/migrations/tenant/*_create_item_barcodes_table.php`, `app/Models/ItemBarcode.php`, factory
- Modify: `app/Models/Item.php`
- Test: `tests/Feature/BarcodeTest.php`

- [ ] **Step 1: Write failing tests** — `test_an_item_holds_several_barcodes`, `test_a_barcode_is_unique_within_the_shop`
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — separate table: one product legitimately carries several codes
- [ ] **Step 4: Verify green and format**

### Task 12: CORE — barcode lookup endpoint

**Files:**
- Create: `app/Http/Controllers/BarcodeLookupController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BarcodeTest.php`

- [ ] **Step 1: Write failing tests** — `test_a_known_barcode_returns_its_item`, `test_an_unknown_barcode_returns_not_found`, `test_the_endpoint_is_rate_limited`, `test_a_guest_cannot_use_it`
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — indexed lookup, minimal payload
- [ ] **Step 4: Verify green and format**

### Task 13: CORE — add a barcode to an item

- [ ] **Step 1: Write failing tests** — `test_a_barcode_is_added_to_an_item`, `test_a_duplicate_barcode_is_rejected`
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

### Task 14: CORE — remove a barcode from an item

- [ ] **Step 1: Write failing tests** — `test_a_barcode_is_removed`, `test_removing_the_last_barcode_leaves_the_item_intact`
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

---

## Module: Variants

### Task 15: CORE — variant storage

**Files:**
- Create: `database/migrations/tenant/*_create_item_variants_table.php`, `app/Models/ItemVariant.php`, factory
- Test: `tests/Feature/VariantTest.php`

- [ ] **Step 1: Write failing tests** — `test_an_item_holds_variants_with_their_own_price_and_stock`, `test_a_variant_inherits_the_parent_tax_class`
- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

### Task 16: CORE — create a variant
- [ ] **Step 1: Write failing tests** — created, name required, price optional
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 17: CORE — update a variant
- [ ] **Step 1: Write failing tests** — updated, price change logged
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 18: CORE — delete a variant
- [ ] **Step 1: Write failing tests** — deleted when unsold, refused when it has sale history
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 19: CORE — sell a variant line
- [ ] **Step 1: Write failing tests** — `test_a_sale_line_records_the_variant`, `test_stock_draws_from_the_variant`
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Customers and ledger

### Task 20: CORE — customers, part 1: list
**Files:** migration, `app/Models/Customer.php`, factory, controller, index view, routes, `tests/Feature/CustomerTest.php`
- [ ] **Step 1: Write failing tests** — empty state, search by name and phone
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 21: CORE — customers, part 2: create
- [ ] **Step 1: Write failing tests** — created, name required, phone normalised, duplicate phone warns not blocks
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 22: CORE — customers, part 3: update
- [ ] **Step 1: Write failing tests** — updated, balance untouched by an edit
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 23: CORE — customers, part 4: deactivate
- [ ] **Step 1: Write failing tests** — deactivated not deleted, a customer with history is never hard-deleted, deactivated customers are hidden from the counter picker
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 24: CORE — ledger entries table

**Files:** migration, `app/Models/CustomerLedgerEntry.php`, factory, `tests/Feature/CustomerLedgerTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_an_entry_records_type_amount_and_who_recorded_it(): void;
public function test_entries_are_append_only(): void;
public function test_a_balance_is_the_sum_of_entries(): void;
```

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 25: CORE — cached balance
- [ ] **Step 1: Write failing tests** — `test_the_cached_balance_matches_a_recomputation`, `test_the_balance_rebuilds_from_entries`
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 26: CORE — record a credit sale
- [ ] **Step 1: Write failing tests** — `test_a_credit_sale_writes_a_sale_and_a_charge_in_one_transaction`, `test_a_failed_charge_rolls_back_the_sale`
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 27: CORE — record a payment against a balance
- [ ] **Step 1: Write failing tests** — payment recorded, needs its own permission separate from making a sale, cannot be negative
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 28: CORE — record an adjustment
- [ ] **Step 1: Write failing tests** — adjustment requires a note, is permissioned, appears in the activity log
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 29: CORE — outstanding balances report with ageing
- [ ] **Step 1: Write failing tests** — balances listed descending, ageing buckets at 30/60/90 days, customers with zero balance excluded
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 30: CORE — customer statement document
- [ ] **Step 1: Write failing tests** — statement lists entries in order with a running balance, is permissioned
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Retail pack

### Task 31: PACK — retail pack shell and product types
**Files:** `app/Packs/Retail/RetailPack.php`, tests
- [ ] **Step 1: Write failing tests** — declares `packaged_good`, `loose_good`, `bundle`; loose goods are measured; packaged goods carry barcodes and fixed prices
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 32: PACK — departments, part 1: list
- [ ] **Step 1: Write failing tests** — empty state, nested tree renders
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 33: PACK — departments, part 2: create
- [ ] **Step 1: Write failing tests** — created, name required, optional parent, no cyclic parent
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 34: PACK — departments, part 3: update
- [ ] **Step 1: Write failing tests** — renamed, reparented, a department cannot become its own ancestor
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 35: PACK — departments, part 4: delete
- [ ] **Step 1: Write failing tests** — empty department deleted, one holding items refused, one with children refused
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 36: PACK — scan-led POS screen skeleton
- [ ] **Step 1: Write a failing test** that a retail shop resolves this screen and the barcode field is present and autofocused
- [ ] **Step 2: Verify red** · **Step 3: Implement** — compose the core cart, totals, payment components · **Step 4: Green and format**

### Task 37: PACK — scan to cart
- [ ] **Step 1: Write failing tests** — a known barcode adds a line, scanning the same code twice increments quantity, focus stays in the barcode field
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 38: PACK — unknown barcode inline create
- [ ] **Step 1: Write failing tests** — unknown code offers inline create, created item is added to the cart, the flow is permissioned
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 39: PACK — quick-sell tiles
- [ ] **Step 1: Write failing tests** — pinned items render as tiles, a tile adds a line, tile order is configurable
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 40: PACK — loose goods by typed weight
- [ ] **Step 1: Write failing tests** — a measured item prompts for weight, price is weight times unit price, stock draws the typed weight
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 41: PACK — rounding line
- [ ] **Step 1: Write failing tests** — total rounds to the nearest rupee, the rounding appears as its own line, takings reconcile
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 42: PACK — udhaar at the counter
- [ ] **Step 1: Write failing tests** — a credit sale requires a customer, writes a charge, and is permissioned
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 43: PACK — hold a bill
- [ ] **Step 1: Write failing tests** — a cart is held and restored, held carts are per-user, a held cart does not draw stock
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 44: PACK — return and exchange
- [ ] **Step 1: Write failing tests** — a returned line restores stock, a refund is recorded, a return references its original sale, a return cannot exceed the quantity sold
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 45: PACK — receipt document
- [ ] **Step 1: Write failing tests** — receipt shows tax decomposed out of the total, shows the rounding line, shows the balance for a credit sale
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 46: PACK — seed data
- [ ] **Step 1: Write failing tests** — a starter department tree is seeded, no starter catalogue is seeded, seeding is idempotent
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 47: Phase 1 acceptance
- [ ] **Step 1: Full suite green, nothing weakened**
- [ ] **Step 2: Provision a kirana shop, scan-sell ten items, take cash, print a receipt**
- [ ] **Step 3: Sell on credit, take a part payment, check the statement and the ageing report**
- [ ] **Step 4: Confirm the oil-change shop is entirely unaffected**
- [ ] **Step 5: `vendor/bin/pint --dirty --format agent`**
