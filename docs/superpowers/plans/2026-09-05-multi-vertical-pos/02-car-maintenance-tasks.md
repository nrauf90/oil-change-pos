# Car Maintenance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a car-maintenance counter built on job cards, and pay the rule-of-two debt by moving vehicles, service history and inspections into the core first.

**Architecture:** Automotive domain graduates from the oil-change pack into the core. A new car-maintenance pack adds job cards, labour operations and technician assignment on top.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 5, PHPUnit 12, Blade, Alpine.js.

**Spec:** [`pack-car-maintenance.md`](../../specs/2026-09-05-multi-vertical-pos/pack-car-maintenance.md)

**Depends on:** [Foundation](00-foundation-tasks.md) and [Retail](01-retail-tasks.md) complete.

---

## Brainstorm

**What a workshop actually does.** A car arrives with a complaint. Someone looks at it and writes an estimate. The customer approves — or approves part of it. Work happens over hours or days, parts get added, a technician is assigned. At the end it is invoiced, often with a deposit already taken.

**Why this vertical matters more than its size.** It is the first real test of the rule of two. Two automotive packs now need vehicles, service history and inspections. If those move to the core cleanly here, the architecture holds. If this step is skipped, the codebase gets two divergent copies of the same domain and every later vehicle change costs twice.

**Decisions taken without asking:**

- **Approval is captured, never assumed.** Who approved, when, and against which version of the estimate. An estimate that silently becomes a job is how workshops end up in disputes.
- **An estimate is versioned.** Adding work after approval creates a revision needing its own approval, rather than quietly editing an approved number.
- **Partial approval is normal.** A customer approves the brakes and declines the suspension. Declined lines stay on the card as declined — they are next visit's upsell and they protect the workshop if the car comes back.
- **Labour is a priced operation**, not free text: a named job with standard hours and a rate, so "front brake pads, both sides" prices consistently and reports meaningfully.
- **One technician owns a job**, with helpers recorded. Shared ownership makes utilisation reporting meaningless.
- **A job card can be invoiced in parts** — deposit then balance. Common on larger jobs.
- **Vehicle-in and vehicle-out condition notes with photos.** Reuses the receipt-image machinery already built for expenses. Protects the workshop against damage claims.
- **Estimate → invoice never loses the original.** The estimate is retained even when the final differs.

**Deliberately excluded:** parts ordering from suppliers mid-job, warranty claim tracking, courtesy-car management, appointment scheduling. All real; none needed to sell the first workshop.

## Global Constraints

- **Task 1 is not optional and must land first.** Do not start the pack while automotive tables still belong to the oil-change pack.
- The live oil-change shop must behave identically after Task 1–4.
- A job card is operational state; the sale it produces stays an immutable financial record.

---

## Module: Rule-of-two migration (automotive to core)

### Task 1: CORE — move vehicle tables out of the oil-change pack

**Files:**
- Modify: `app/Packs/OilChange/OilChangePack.php`
- Move: vehicle, service-history and inspection migrations from the pack directory into core migrations
- Test: `tests/Feature/Packs/AutomotiveCoreMigrationTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_vehicle_tables_exist_for_a_shop_without_the_oil_change_pack(): void;
public function test_the_oil_change_pack_no_longer_owns_vehicle_migrations(): void;
public function test_an_existing_oil_change_shop_keeps_all_its_vehicle_data(): void;
```

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — move `vehicle_makes`, `vehicle_models`, `customer_vehicles`, `item_vehicle_compatibilities`, `inspections`, `inspection_items` to core
- [ ] **Step 4: Verify green on a copy of live-shaped data, then format**

### Task 2: CORE — move the vehicle field group to core

- [ ] **Step 1: Write failing tests** — the group is available to any pack, validation unchanged, still stores to `customer_vehicles`
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 3: CORE — move service history to core

- [ ] **Step 1: Write failing tests** — lookup by phone works without the oil-change pack, existing history is unchanged, the permission moves with it
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 4: CORE — move inspections to core

- [ ] **Step 1: Write failing tests** — inspection CRUD works without the oil-change pack, the `workshop` module gate still applies
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 5: Verify the oil-change shop is unchanged

- [ ] **Step 1: Full suite green, nothing weakened**
- [ ] **Step 2: Compare the live shop's screens, invoices and reports against a pre-migration capture.** Any difference is a defect.

---

## Module: Labour operations

### Task 6: PACK — labour operations, part 1: list
**Files:** migration, `app/Models/LabourOperation.php`, factory, controller, index view, routes, test
- [ ] **Step 1: Write failing tests** — empty state, list shows name, standard hours and rate
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 7: PACK — labour operations, part 2: create
- [ ] **Step 1: Write failing tests** — created, name required, standard hours positive, rate positive
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 8: PACK — labour operations, part 3: update
- [ ] **Step 1: Write failing tests** — updated, a rate change does not alter past job cards
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 9: PACK — labour operations, part 4: deactivate
- [ ] **Step 1: Write failing tests** — deactivated not deleted, one with history is never hard-deleted, deactivated operations hidden from the picker
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 10: PACK — labour price resolver
- [ ] **Step 1: Write failing tests** — price is standard hours times rate, an override is permissioned and logged
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Technicians

### Task 11: PACK — mark a user as a technician
- [ ] **Step 1: Write failing tests** — a user is flagged as a technician, only technicians appear in the assignment picker
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 12: PACK — assign a technician to a job card
- [ ] **Step 1: Write failing tests** — one owner per card, assignment is permissioned, assignment is logged
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 13: PACK — record helpers on a job card
- [ ] **Step 1: Write failing tests** — helpers recorded separately from the owner, helpers do not affect utilisation attribution
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Job cards

### Task 14: PACK — job card storage
**Files:** `job_cards`, `job_card_lines` migrations, models, factories, test
- [ ] **Step 1: Write failing tests** — a card belongs to a vehicle and a customer, holds lines of parts and labour, has a status
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 15: PACK — open a job card
- [ ] **Step 1: Write failing tests** — opened against a vehicle, records the complaint, records who opened it, status is `estimating`
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 16: PACK — list open job cards
- [ ] **Step 1: Write failing tests** — open cards listed newest first, filterable by technician and status, closed cards excluded by default
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 17: PACK — view a job card
- [ ] **Step 1: Write failing tests** — shows vehicle, complaint, lines, approvals and totals; permissioned
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 18: PACK — add a part line
- [ ] **Step 1: Write failing tests** — a part line is added with its price snapshot, stock is not drawn until invoicing
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 19: PACK — add a labour line
- [ ] **Step 1: Write failing tests** — labour line priced from the operation, hours may be overridden with permission
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 20: PACK — edit a line
- [ ] **Step 1: Write failing tests** — quantity and price edited before approval, an approved line cannot be silently edited
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 21: PACK — remove a line
- [ ] **Step 1: Write failing tests** — removed before approval, an approved line is declined rather than removed
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Estimates and approval

### Task 22: PACK — create an estimate from a job card
- [ ] **Step 1: Write failing tests** — estimate snapshots the current lines and totals, is versioned from 1
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 23: PACK — record approval
- [ ] **Step 1: Write failing tests** — records who approved, when, and which version; an unapproved card cannot move to `in_progress`
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 24: PACK — partial approval
- [ ] **Step 1: Write failing tests** — individual lines are approved or declined, declined lines remain visible, only approved lines invoice
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 25: PACK — revise an estimate
- [ ] **Step 1: Write failing tests** — adding work after approval creates version 2, version 2 needs its own approval, version 1 is retained
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 26: PACK — estimate document
- [ ] **Step 1: Write failing tests** — estimate prints with vehicle, lines, totals and a signature area; shows its version
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Vehicle condition

### Task 27: PACK — vehicle-in condition notes and photos
- [ ] **Step 1: Write failing tests** — notes and images stored via `TenantStoragePath`, images served only through a permissioned action, optional
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 28: PACK — vehicle-out condition notes and photos
- [ ] **Step 1: Write failing tests** — recorded at close, in and out sets are distinguishable
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Invoicing

### Task 29: PACK — take a deposit
- [ ] **Step 1: Write failing tests** — a deposit is recorded against the card, appears on the ledger, reduces the balance due
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 30: PACK — close a job card to a sale
- [ ] **Step 1: Write failing tests**

```php
public function test_closing_writes_a_sale_from_approved_lines_only(): void;
public function test_closing_draws_stock_for_part_lines(): void;
public function test_a_closed_card_cannot_be_reopened(): void;
public function test_a_deposit_is_applied_against_the_invoice(): void;
```

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 31: PACK — final invoice document
- [ ] **Step 1: Write failing tests** — invoice shows parts and labour separately, shows the deposit, shows declined work as declined
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Pack wiring

### Task 32: PACK — car-maintenance pack shell and product types
- [ ] **Step 1: Write failing tests** — declares `part`, `labour_operation`, `consumable`; labour holds no stock
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 33: PACK — job-led POS screen
- [ ] **Step 1: Write failing tests** — open jobs shown first, a walk-in counter sale still works
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 34: PACK — seed data
- [ ] **Step 1: Write failing tests** — a starter labour-operation list is seeded, seeding is idempotent
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 35: PACK — technician utilisation report
- [ ] **Step 1: Write failing tests** — hours by technician over a window, only owners counted, excludes declined lines
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 36: PACK — labour versus parts revenue report
- [ ] **Step 1: Write failing tests** — split by line type over a window, matches invoice totals
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 37: Phase 2 acceptance
- [ ] **Step 1: Full suite green, nothing weakened**
- [ ] **Step 2: Open a job card, estimate, partially approve, add work, revise, approve, invoice**
- [ ] **Step 3: Confirm the oil-change shop is unaffected and shares one copy of the vehicle domain**
- [ ] **Step 4: `vendor/bin/pint --dirty --format agent`**
