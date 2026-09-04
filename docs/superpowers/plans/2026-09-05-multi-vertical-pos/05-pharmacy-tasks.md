# Pharmacy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a pharmacy counter, and build lot-tracked stock in the core — which also delivers true cost-of-goods reporting to every other vertical.

**Architecture:** A stock ledger with lots, where `items.stock_level` becomes a derived cache. First-expiry-first draw-down selected by product type. The pharmacy pack adds salt search, nested units, prescriptions and the controlled register.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 5, PHPUnit 12, Blade, Alpine.js.

**Spec:** [`stock-lots.md`](../../specs/2026-09-05-multi-vertical-pos/stock-lots.md), [`pack-pharmacy.md`](../../specs/2026-09-05-multi-vertical-pos/pack-pharmacy.md)

**Depends on:** Phases 0–4 complete.

---

## Brainstorm

**What a pharmacy counter actually does.** A customer arrives with a script or asks for a generic. The pharmacist searches by **salt**, sees which brands are in stock, checks expiry, may substitute, sells part of a strip, and records the script if it needs recording.

**Why it is last.** It carries the largest data change, and it is the one trade where a wrong model is a compliance problem rather than an inconvenience. It should inherit a stock ledger two other trades have already exercised.

**Decisions taken without asking:**

- **First-expiry-first is automatic, with a permissioned override.** A pharmacist sometimes needs a specific batch, and forcing the system's choice would be wrong.
- **Expired stock is blocked from sale**, overridable only with permission and always logged. Blocking by default is the safe direction.
- **Minimum shelf life at sale is configurable and warns rather than blocks**, defaulting to off until a pharmacist sets it.
- **Batch and expiry are visible on every counter line.** A pharmacist checks by habit; hiding it makes the screen untrustworthy.
- **Sale returns go back to their own lot.** A return that increments a total stock number corrupts expiry tracking — this is a correctness issue, not a nicety.
- **Substitution is recorded**: what was asked for, what was given.
- **Nested units are three levels** — box, strip, unit — and the counter sells at any level while stock draws at the smallest.
- **The controlled register is append-only**, its own permission, extractable as a document.
- **Panels are included but last**, and only wired to the existing customer ledger — a panel is a customer that happens to be an organisation.

**Excluded, deliberately and importantly:** **clinical decision support — drug interactions, contraindications, allergy and dose checking — is out of scope and must not be hand-rolled.** A wrong or incomplete warning is more dangerous than none because staff come to rely on it. It requires a licensed clinical database and a contract establishing responsibility. Do not add it quietly as "just a warning".

**Also excluded:** temperature logging hardware, e-prescription integration, insurance claim adjudication.

## Global Constraints

- **Confirm regulatory requirements with a pharmacist and an accountant before Task 20.** Record-keeping, controlled substances, prescription retention and pricing rules may add required fields and constrain what may be edited or deleted. Treat every regulatory statement in the spec as *to verify*, never as established.
- `stock_level` is a cache and must always be rebuildable from movements.
- Movements are append-only; a correction is a compensating movement.
- A lot's remaining quantity never goes negative.

---

## Module: Stock lots (core)

### Task 1: CORE — lot storage
- [ ] **Step 1: Write failing tests** — a lot records batch, expiry, received and remaining quantity, unit cost and supplier
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 2: CORE — stock movement storage
- [ ] **Step 1: Write failing tests** — a movement records item, lot, quantity, direction, reason and who recorded it; movements are append-only
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 3: CORE — `StockStrategy` contract
- [ ] **Step 1: Write failing tests** — the strategy is selected by the product type's `stock_mode`, not by the shop
- [ ] **Step 2: Verify red** · **Step 3: Implement** — `NoneStockStrategy`, `SimpleStockStrategy`, `LotStockStrategy` resolved from the container · **Step 4: Green and format**

### Task 4: CORE — none and simple strategies preserve today's behaviour
- [ ] **Step 1: Write failing tests** — a `none` type draws nothing; a `simple` type decrements exactly as today; existing stock tests still pass unchanged
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 5: CORE — first-expiry-first draw-down
- [ ] **Step 1: Write failing tests**

```php
public function test_a_sale_draws_from_the_lot_expiring_first(): void;
public function test_a_line_larger_than_one_lot_splits_across_lots(): void;
public function test_a_lot_never_goes_negative(): void;
public function test_overselling_a_lot_tracked_item_is_a_hard_error(): void;
```

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 6: CORE — rebuild `stock_level` from movements
- [ ] **Step 1: Write failing tests** — the cache matches a recomputation; a rebuild command restores a corrupted cache
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 7: CORE — migrate existing stock into opening lots
- [ ] **Step 1: Write failing tests**

```php
public function test_each_item_with_stock_gains_one_opening_lot(): void;
public function test_the_opening_lot_carries_current_quantity_and_unit_cost(): void;
public function test_the_opening_lot_has_no_batch_or_expiry(): void;
public function test_the_migration_is_idempotent(): void;
```

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Verify on live-shaped data, then format**

### Task 8: CORE — receive stock by lot
- [ ] **Step 1: Write failing tests** — receiving records batch, expiry, quantity and per-unit cost; extends the existing supply flow rather than replacing it
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 9: CORE — block expired stock at sale
- [ ] **Step 1: Write failing tests** — selling an expired lot is refused; an override requires permission and is logged
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 10: CORE — minimum shelf life rule
- [ ] **Step 1: Write failing tests** — a configurable horizon warns at sale; off by default; the warning is not a block
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 11: CORE — stock adjustment
- [ ] **Step 1: Write failing tests** — an adjustment writes a movement with a reason, is permissioned, and appears in the activity log
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 12: CORE — write off expired stock
- [ ] **Step 1: Write failing tests** — a write-off zeroes the lot, records the value lost, is permissioned
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 13: CORE — near-expiry report
- [ ] **Step 1: Write failing tests** — lots expiring within a configurable horizon, sorted by date, with value at risk
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 14: CORE — cost of goods from lots
- [ ] **Step 1: Write failing tests** — margin computed against the drawn lot's cost, not the item's nominal cost; benefits every vertical
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Sale returns (core)

### Task 15: CORE — return a sale line
- [ ] **Step 1: Write failing tests**

```php
public function test_a_returned_unit_goes_back_to_its_own_lot(): void;
public function test_a_return_cannot_exceed_the_quantity_sold(): void;
public function test_a_return_records_a_refund_and_references_the_original_sale(): void;
```

The first is a correctness requirement, not a nicety — returning to a total corrupts expiry tracking.

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 16: CORE — return to supplier
- [ ] **Step 1: Write failing tests** — a lot is returned to its supplier, stock reduces, the supplier ledger records a credit
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 17: CORE — return-to-supplier candidates report
- [ ] **Step 1: Write failing tests** — near-expiry lots grouped by supplier with value
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Nested units (core)

### Task 18: CORE — three-level unit hierarchy
- [ ] **Step 1: Write failing tests** — an item declares box, strip and unit counts; conversions are exact; two-level items are unaffected
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 19: CORE — sell at any level
- [ ] **Step 1: Write failing tests** — selling six units from a strip of ten draws six at the smallest level; the line shows the level sold
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Pharmacy catalogue

> **Confirm regulatory requirements before starting this module.**

### Task 20: PACK — pack shell and product types
- [ ] **Step 1: Write failing tests** — declares `medicine` (`stock_mode: lot`), `otc`, `surgical`, `cold_chain`, `service`
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 21: PACK — salt, strength and form on a medicine
- [ ] **Step 1: Write failing tests** — stored as indexed columns, not JSON; required for `medicine`; optional elsewhere
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 22: PACK — search by salt
- [ ] **Step 1: Write failing tests**

```php
public function test_searching_a_salt_returns_every_brand_carrying_it(): void;
public function test_results_show_current_stock_and_nearest_expiry(): void;
public function test_search_by_brand_still_works(): void;
public function test_the_search_stays_within_its_query_budget(): void;
```

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 23: PACK — substitution suggestions
- [ ] **Step 1: Write failing tests** — same salt and strength, ranked by stock and expiry, excludes out-of-stock and expired
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 24: PACK — record a substitution
- [ ] **Step 1: Write failing tests** — the line records what was asked for and what was given; reportable
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 25: PACK — cold-chain flag
- [ ] **Step 1: Write failing tests** — flagged items are separated on stock reports and marked on the dispensing label
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Prescriptions

### Task 26: PACK — prescription storage
- [ ] **Step 1: Write failing tests** — records prescriber, patient and date; links to the sale that dispensed against it
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 27: PACK — attach a prescription image
- [ ] **Step 1: Write failing tests** — stored via `TenantStoragePath` on the private disk, served only through a permissioned action, images and PDF accepted
- [ ] **Step 2: Verify red** · **Step 3: Implement** — reuse the expense-receipt pattern exactly · **Step 4: Green and format**

### Task 28: PACK — prescription field group at the counter
- [ ] **Step 1: Write failing tests** — the block validates and stores to `prescriptions`, never to `sales`; optional for OTC
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 29: PACK — view a prescription and what was dispensed
- [ ] **Step 1: Write failing tests** — shows the script, its image and every line dispensed against it; permissioned
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Controlled substances

### Task 30: PACK — schedule flag on a medicine
- [ ] **Step 1: Write failing tests** — a medicine may be marked scheduled; the flag drives the rules below
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 31: PACK — require a prescription for a scheduled medicine
- [ ] **Step 1: Write failing tests** — dispensing without a linked prescription is refused; enforced, not advisory
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 32: PACK — register entry on dispensing
- [ ] **Step 1: Write failing tests** — an entry records what, how much, to whom, against which script and prescriber, by whom, when
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 33: PACK — register is append-only
- [ ] **Step 1: Write failing tests** — entries cannot be edited or deleted; a correction is a compensating entry; has its own permission
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 34: PACK — register extract document
- [ ] **Step 1: Write failing tests** — extract covers a date range, is permissioned, and is stable across re-runs
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Counter and documents

### Task 35: PACK — search-led POS screen
- [ ] **Step 1: Write failing tests** — search focused on load, salt and brand search both reachable, batch and expiry on every line
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 36: PACK — lot override at the counter
- [ ] **Step 1: Write failing tests** — the pharmacist may pick a specific lot, the override is permissioned and logged
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 37: PACK — dispensing label document
- [ ] **Step 1: Write failing tests** — shows item, strength, quantity, batch and expiry, and the prescriber's own instructions only
- [ ] **Step 2: Verify red** · **Step 3: Implement** — no generated medical advice · **Step 4: Green and format**

### Task 38: PACK — refill tracking
- [ ] **Step 1: Write failing tests** — a customer may be marked on a recurring medication; a refills-due view lists them; opt-in
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Panels (optional, build last)

### Task 39: PACK — panels, part 1: list
- [ ] **Step 1: Write failing tests** — empty state, panels with their coverage rule
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 40: PACK — panels, part 2: create
- [ ] **Step 1: Write failing tests** — created with a coverage rule, name required, coverage between zero and one
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 41: PACK — panels, part 3: update
- [ ] **Step 1: Write failing tests** — updated; a coverage change does not alter past sales
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 42: PACK — panels, part 4: deactivate
- [ ] **Step 1: Write failing tests** — deactivated not deleted; existing receivables survive
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 43: PACK — split a sale between patient and panel
- [ ] **Step 1: Write failing tests** — the split follows the coverage rule, the patient portion settles at the counter, the panel portion becomes a ledger charge, the two sum to the total
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 44: PACK — panel statement document
- [ ] **Step 1: Write failing tests** — monthly statement per panel with every covered line and the total claimed
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

### Task 45: PACK — reports
- [ ] **Step 1: Write failing tests** — near-expiry, write-offs, returns by reason, register extract, panel receivables, refills due, fast and slow movers by salt
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 46: PACK — seed data
- [ ] **Step 1: Write failing tests** — starter dosage forms seeded, no starter catalogue, idempotent
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 47: Phase 5 acceptance
- [ ] **Step 1: Full suite green, nothing weakened**
- [ ] **Step 2: Receive two lots of one medicine with different expiries; confirm the near one sells first**
- [ ] **Step 3: Sell six units from a strip; confirm stock draws at the smallest level**
- [ ] **Step 4: Dispense a scheduled medicine; confirm a script is required and the register entry is written and immutable**
- [ ] **Step 5: Return a line; confirm it goes back to its own lot**
- [ ] **Step 6: Confirm every other vertical now reports cost of goods from lots**
- [ ] **Step 7: `vendor/bin/pint --dirty --format agent`**
