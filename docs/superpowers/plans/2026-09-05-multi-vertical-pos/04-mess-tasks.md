# Mess, Canteen and Tiffin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a subscriber-led counter for a daily-meals business, and build customer subscriptions in the core.

**Architecture:** Plans and subscriptions generate charges against the customer ledger built in Phase 1. Meals consumed are **attendance**, not sales. Delivery and the kitchen transport are inherited from Phase 3.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 5, PHPUnit 12, Blade, Alpine.js.

**Spec:** [`subscriptions.md`](../../specs/2026-09-05-multi-vertical-pos/subscriptions.md), [`pack-mess.md`](../../specs/2026-09-05-multi-vertical-pos/pack-mess.md)

**Depends on:** Phases 0–3 complete. Delivery and kitchen transport must already be in the core.

---

## Brainstorm

**What a mess actually does.** People sign up for a month. They eat once or twice a day. Someone marks who ate. At the end (or start) of the cycle a bill is raised against their account. A few walk-ins buy single meals. The kitchen needs to know how many portions to cook, which is a headcount question, not an order question.

**The distinction everything rests on:** a subscription is an agreement that generates charges; meals consumed against it are attendance. **A consumed meal must never create a sale.** Collapsing the two makes every revenue report wrong and is very hard to unpick.

**Decisions taken without asking:**

- **Assume present unless marked absent.** At a busy serving window, marking 80 people present is unworkable; marking the 6 who are away is trivial. This is how messes actually run.
- **Absence is recorded in advance where possible** — a subscriber says on Sunday that they are away Monday — because that is what makes the production sheet accurate.
- **Unused meals lapse by default**, with carry-forward as a per-plan flag left unbuilt until a customer asks. Under advance billing a carried-forward meal is a liability the shop still owes, so it is more than a counting exercise.
- **Mid-cycle joiners are pro-rated by day.** Charging a full month to someone joining on the 25th loses the customer.
- **Both billing modes**, per plan, arrears by default. See the sign-convention rule below.
- **A pause is first-class.** Subscribers travel. Pausing stops future cycles without ending the agreement or losing history.
- **Walk-ins are ordinary sales** through the ordinary counter, reported separately from subscription revenue.
- **The production sheet counts expected attendance plus walk-in orders already taken**, per meal slot.
- Guests: a subscriber bringing someone is a walk-in sale, not an entitlement draw.

**Deliberately excluded:** per-subscriber dietary preferences, route planning for tiffin delivery, ingredient-level recipe costing, biometric attendance.

## Global Constraints

- **A consumption event never creates a sale and never touches the cash drawer.**
- Cycle billing is idempotent per subscription per period.
- The billing mode is snapshotted on the subscription at signup. **No screen or statement may show a bare balance without its mode** — under arrears a negative balance is overdue, under advance it is impossible.
- Plan price is snapshotted at signup; changing a plan never rewrites past cycles.

---

## Module: Plans

### Task 1: CORE — plans, part 1: list
**Files:** `plans` migration, model, factory, controller, index view, routes, test
- [ ] **Step 1: Write failing tests** — empty state, list shows price, cycle length and billing mode
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 2: CORE — plans, part 2: create
- [ ] **Step 1: Write failing tests** — created, name required, price positive, cycle length valid, billing mode is `advance` or `arrears`
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 3: CORE — plans, part 3: update
- [ ] **Step 1: Write failing tests** — updated, a price change does not alter existing subscriptions, a billing-mode change does not alter existing subscriptions
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 4: CORE — plans, part 4: deactivate
- [ ] **Step 1: Write failing tests** — deactivated not deleted, hidden from signup, existing subscriptions continue
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 5: CORE — plan entitlements
- [ ] **Step 1: Write failing tests** — a plan declares meals per day and days per cycle; entitlement is computable for a period
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Subscriptions

### Task 6: CORE — subscriptions storage
- [ ] **Step 1: Write failing tests** — belongs to a customer and a plan; snapshots price and billing mode at signup; has a status
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 7: CORE — start a subscription
- [ ] **Step 1: Write failing tests** — starts on a date, status `active`, a customer cannot hold two active subscriptions on the same plan
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 8: CORE — list subscriptions
- [ ] **Step 1: Write failing tests** — active listed by default, filterable by status and plan, searchable by customer
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 9: CORE — pause a subscription
- [ ] **Step 1: Write failing tests** — status `paused`, no future cycles generated, past cycles retained, pause is dated
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 10: CORE — resume a subscription
- [ ] **Step 1: Write failing tests** — status returns to `active`, the paused span is recorded, billing resumes from the resume date
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 11: CORE — end a subscription
- [ ] **Step 1: Write failing tests** — status `ended` with a date, no further cycles, a final arrears cycle is still billable
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Billing cycles

### Task 12: CORE — cycle storage
- [ ] **Step 1: Write failing tests** — a cycle records its period, amount and the ledger entry it created
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 13: CORE — generate an advance cycle
- [ ] **Step 1: Write failing tests**

```php
public function test_an_advance_cycle_charges_at_period_start(): void;
public function test_the_charge_is_the_full_entitlement_price(): void;
public function test_the_balance_reads_as_credit_remaining(): void;
```

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 14: CORE — generate an arrears cycle
- [ ] **Step 1: Write failing tests**

```php
public function test_an_arrears_cycle_charges_at_period_end(): void;
public function test_the_charge_reflects_what_was_consumed(): void;
public function test_the_balance_reads_as_amount_owed(): void;
```

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 15: CORE — cycle billing is idempotent
- [ ] **Step 1: Write failing tests** — running billing twice for the same period creates one charge; a partially failed run resumes safely
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 16: CORE — pro-rate a mid-cycle joiner
- [ ] **Step 1: Write failing tests** — a joiner mid-period is charged by remaining days; a joiner on day one is charged in full; rounding is deterministic
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 17: CORE — billing command
- [ ] **Step 1: Write failing tests** — a command bills all due cycles, is safe to re-run, reports what it did, respects paused subscriptions
- [ ] **Step 2: Verify red** · **Step 3: Implement** — cron-driven; the shared host has no daemon · **Step 4: Green and format**

### Task 18: CORE — mode-aware balance presentation
- [ ] **Step 1: Write failing tests**

```php
public function test_an_arrears_balance_renders_as_owed(): void;
public function test_an_advance_balance_renders_as_credit_remaining(): void;
public function test_a_balance_is_never_rendered_without_its_mode(): void;
```

The third is the trap this whole module turns on. Assert it.

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Attendance

### Task 19: CORE — consumption event storage
- [ ] **Step 1: Write failing tests** — records subscription, date, meal slot and who marked it; unique per subscription per slot per day
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 20: CORE — a consumption event never creates a sale
- [ ] **Step 1: Write failing tests**

```php
public function test_marking_consumption_creates_no_sale(): void;
public function test_marking_consumption_does_not_touch_the_cash_drawer(): void;
```

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 21: PACK — mark an absence
- [ ] **Step 1: Write failing tests** — absence recorded for a date and slot, may be recorded in advance, absence removes the expected headcount
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 22: PACK — remove an absence
- [ ] **Step 1: Write failing tests** — absence withdrawn, headcount restored, withdrawal after the slot has passed is refused
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 23: PACK — assume-present resolution
- [ ] **Step 1: Write failing tests** — an active subscriber with no absence counts as present; a paused subscriber never counts; an ended subscriber never counts
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Menu cycles

### Task 24: PACK — menu cycle, part 1: list
- [ ] **Step 1: Write failing tests** — empty state, menu shown by day and slot
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 25: PACK — menu cycle, part 2: create a day's menu
- [ ] **Step 1: Write failing tests** — dishes assigned to a day and slot, a slot may hold several dishes
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 26: PACK — menu cycle, part 3: update a day's menu
- [ ] **Step 1: Write failing tests** — updated, a past day's menu is preserved for reporting
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 27: PACK — menu cycle, part 4: delete a day's menu
- [ ] **Step 1: Write failing tests** — a future day is cleared, a past day is refused
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Mess pack

### Task 28: PACK — pack shell and product types
- [ ] **Step 1: Write failing tests** — declares `meal_plan` with `pricing_mode: plan`, `daily_dish` not separately sellable to subscribers, ordinary sellable items for walk-ins
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 29: PACK — plan price resolver
- [ ] **Step 1: Write failing tests** — a `plan` type prices per cycle, not per line; it never appears as a counter line
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 30: PACK — subscriber-led POS screen
- [ ] **Step 1: Write failing tests** — find a subscriber by name or phone, balance shown in its mode, walk-in falls through to an ordinary sale
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 31: PACK — take a payment against a balance
- [ ] **Step 1: Write failing tests** — payment writes a ledger entry, is permissioned, updates the displayed balance in its mode
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 32: PACK — production sheet view
- [ ] **Step 1: Write failing tests**

```php
public function test_the_sheet_counts_expected_subscribers_per_slot(): void;
public function test_it_adds_walk_in_orders_already_taken(): void;
public function test_it_excludes_recorded_absences(): void;
public function test_it_renders_on_the_core_kitchen_transport(): void;
```

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 33: PACK — tiffin delivery
- [ ] **Step 1: Write failing tests** — a subscriber may be flagged for delivery, the core delivery flow is reused, no new delivery machinery is added
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 34: PACK — subscriber statement document
- [ ] **Step 1: Write failing tests** — statement shows charges, payments and a running balance in its mode; shows the period
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 35: PACK — monthly invoice document
- [ ] **Step 1: Write failing tests** — invoice shows the cycle, entitlement and amount; pro-rated cycles say so
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 36: PACK — active and lapsed subscriber report
- [ ] **Step 1: Write failing tests** — active, paused and ended counts; lapsed subscribers listed with their last cycle
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 37: PACK — expected versus actual attendance report
- [ ] **Step 1: Write failing tests** — expected headcount against consumption per slot over a window; highlights persistent absentees
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 38: PACK — revenue split report
- [ ] **Step 1: Write failing tests** — subscription revenue and walk-in revenue reported **separately**, never silently combined; totals reconcile
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 39: PACK — seed data
- [ ] **Step 1: Write failing tests** — starter meal slots seeded, no starter plans, idempotent
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 40: Phase 4 acceptance
- [ ] **Step 1: Full suite green, nothing weakened**
- [ ] **Step 2: Sign up an arrears subscriber and an advance subscriber; confirm their balances read correctly and oppositely**
- [ ] **Step 3: Mark absences, produce the production sheet, bill a cycle, take a payment, print a statement**
- [ ] **Step 4: Sell a walk-in meal and confirm it reports separately from subscription revenue**
- [ ] **Step 5: `vendor/bin/pint --dirty --format agent`**
