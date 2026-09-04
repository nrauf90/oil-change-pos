# Restaurant and Hotel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a restaurant counter with tables, dine-in/takeaway/delivery and a kitchen display — and build the order lifecycle, delivery and kitchen transport **in the core**, because the mess inherits all three.

**Architecture:** A mutable `order` in front of the immutable `sale`. Order type, address and rider in the core. Kitchen transport by short polling in the core; the queue view in the pack.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 5, PHPUnit 12, Blade, Alpine.js.

**Spec:** [`order-lifecycle.md`](../../specs/2026-09-05-multi-vertical-pos/order-lifecycle.md), [`delivery.md`](../../specs/2026-09-05-multi-vertical-pos/delivery.md), [`kitchen-screen.md`](../../specs/2026-09-05-multi-vertical-pos/kitchen-screen.md), [`pack-restaurant.md`](../../specs/2026-09-05-multi-vertical-pos/pack-restaurant.md)

**Depends on:** Phases 0–2 complete.

---

## Brainstorm

**What a restaurant counter actually does.** An order opens against a table or as takeaway. Items are added over an hour. Courses are fired to the kitchen when the table is ready for them, not when they were typed. The kitchen cooks in fire order, not entry order. The bill settles at the end, often split.

**Decisions taken without asking:**

- **Fire is a deliberate act, separate from adding.** Typing a dessert at the start must not send it to the kitchen immediately. This single distinction is what makes the system usable in a real dining room.
- **Courses exist** — starter, main, dessert — and fire happens by course.
- **Void needs a reason once fired**, because the kitchen may already have cooked it. Voids are retained and reported; disappearing items are how stock and margin quietly go wrong.
- **Open orders do not reserve stock.** Reserving is correct in theory and adds real complexity; revisit only if it bites.
- **Split by amount and by item**, both. Splitting evenly is the common case; splitting by item is the one that causes arguments if unsupported.
- **A table is a row in a list, not a drawn floor plan.** Floor-plan layout is explicitly out of scope for version one.
- **Orders move between tables.** Cheap to design in, awkward to retrofit.
- **Kitchen tickets clear on a timer, configurable.** Kitchens disagree strongly about this, so it must not be hard-coded.
- **The KDS cannot take money.** It is a display and a state-changer with its own permission. Kitchen staff should not hold counter permissions to see what to cook.
- **Delivery status continues after the kitchen finishes** — `ready` → `out_for_delivery` → `delivered` or `failed` with a reason.
- **Riders are not users.** A rider rarely signs in; they are a record, not an account.

**Hotel-specific:** a room behaves as a table whose bill posts to a customer account rather than settling at the counter. That reuses the ledger from Phase 1 rather than adding anything new.

**Deliberately excluded:** recipes and ingredient depletion, third-party delivery platforms, table reservations, floor-plan drawing, tip distribution.

## Global Constraints

- **Statuses go on `orders`, never on `sales`.** A sale stays a settled financial record.
- Closing an order writes a sale in one transaction; that transition is the audited boundary.
- The kitchen polling endpoint must be cheap. Four screens at three seconds on shared hosting is a self-inflicted outage — index for it and keep the payload small.

---

## Module: Order lifecycle (core)

### Task 1: CORE — orders and order lines storage
**Files:** `orders`, `order_lines` migrations, models, factories, test
- [ ] **Step 1: Write failing tests** — an order holds lines with their own status; an order references a nullable `sale_id`
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 2: CORE — open an order
- [ ] **Step 1: Write failing tests** — records who opened it and when, status `open`, no sale yet
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 3: CORE — list open orders
- [ ] **Step 1: Write failing tests** — open orders only, sorted by opened-at, closed excluded
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 4: CORE — add a line to an order
- [ ] **Step 1: Write failing tests** — line added with price snapshot, status `pending`, order total recalculates
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 5: CORE — edit a line on an order
- [ ] **Step 1: Write failing tests** — quantity edited while `pending`, a fired line cannot be silently edited
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 6: CORE — void a line
- [ ] **Step 1: Write failing tests** — a pending line voids freely, a fired line requires a reason, voided lines are retained and reportable
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 7: CORE — line state transitions
- [ ] **Step 1: Write failing tests**

```php
public function test_a_line_moves_pending_to_fired_to_preparing_to_ready_to_served(): void;
public function test_a_line_cannot_skip_backwards(): void;
public function test_an_invalid_transition_is_rejected(): void;
```

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 8: CORE — close an order to a sale
- [ ] **Step 1: Write failing tests**

```php
public function test_closing_writes_a_sale_from_the_order_lines(): void;
public function test_a_closed_order_cannot_be_reopened(): void;
public function test_voided_lines_do_not_reach_the_sale(): void;
public function test_a_failed_sale_write_rolls_back_the_close(): void;
```

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 9: CORE — abandoned order sweep
- [ ] **Step 1: Write failing tests** — orders open beyond a configurable age are flagged, the sweep never closes them automatically
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Delivery (core)

### Task 10: CORE — order type
- [ ] **Step 1: Write failing tests** — order stores `dine_in`/`takeaway`/`delivery`, defaults sensibly, an invalid type is rejected
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 11: CORE — riders, part 1: list
- [ ] **Step 1: Write failing tests** — empty state, active riders listed
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 12: CORE — riders, part 2: create
- [ ] **Step 1: Write failing tests** — created with name and phone, name required
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 13: CORE — riders, part 3: update
- [ ] **Step 1: Write failing tests** — updated, past deliveries keep their rider
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 14: CORE — riders, part 4: deactivate
- [ ] **Step 1: Write failing tests** — deactivated not deleted, hidden from the assignment picker
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 15: CORE — delivery address on an order
- [ ] **Step 1: Write failing tests** — address required for a delivery order, prefilled from the customer, optional otherwise
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 16: CORE — assign a rider
- [ ] **Step 1: Write failing tests** — assignment recorded with a timestamp, only active riders assignable
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 17: CORE — dispatch and deliver transitions
- [ ] **Step 1: Write failing tests** — `ready` → `out_for_delivery` → `delivered`; a failed delivery records a reason; a delivery order cannot close to a sale until delivered or failed
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 18: CORE — delivery charge line
- [ ] **Step 1: Write failing tests** — charge is its own line, appears on the invoice, never folded into an item price
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 19: CORE — delivery reports
- [ ] **Step 1: Write failing tests** — orders by type, delivery time per rider, failures by reason
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Kitchen transport (core)

### Task 20: CORE — prep stations, part 1: list
- [ ] **Step 1: Write failing tests** — empty state, stations listed in sort order
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 21: CORE — prep stations, part 2: create
- [ ] **Step 1: Write failing tests** — created, name required, sort order defaults
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 22: CORE — prep stations, part 3: update
- [ ] **Step 1: Write failing tests** — renamed and reordered
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 23: CORE — prep stations, part 4: delete
- [ ] **Step 1: Write failing tests** — unused station deleted, one with routed items refused
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 24: CORE — route a line to a station
- [ ] **Step 1: Write failing tests** — station derived from the item's product type, overridable, null station means the default queue
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 25: CORE — outstanding-work endpoint
- [ ] **Step 1: Write failing tests**

```php
public function test_the_endpoint_returns_fired_and_preparing_lines_for_a_station(): void;
public function test_it_excludes_served_and_voided_lines(): void;
public function test_it_requires_the_kitchen_permission(): void;
public function test_it_runs_within_a_small_query_budget(): void;
```

The fourth matters: this is polled every few seconds on shared hosting.

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 26: CORE — kitchen permission
- [ ] **Step 1: Write failing tests** — kitchen staff can read the queue and change line state; cannot change prices or close an order
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 27: CORE — mark preparing and ready from the kitchen
- [ ] **Step 1: Write failing tests** — state changes flow back to the counter, transitions are validated, changes are logged
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 28: CORE — polling client
- [ ] **Step 1: Write failing tests** — the screen polls on an interval, tolerates a failed poll without losing state, interval is configurable
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

## Module: Restaurant pack

### Task 29: PACK — pack shell and product types
- [ ] **Step 1: Write failing tests** — declares `dish`, `beverage`, `combo`, `ingredient`; ingredients are not sellable; dishes hold no stock
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 30: PACK — tables, part 1: list
- [ ] **Step 1: Write failing tests** — empty state, occupied and free shown distinctly
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 31: PACK — tables, part 2: create
- [ ] **Step 1: Write failing tests** — created with a name and seat count, name unique
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 32: PACK — tables, part 3: update
- [ ] **Step 1: Write failing tests** — renamed and reseated, an occupied table can still be edited
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 33: PACK — tables, part 4: delete
- [ ] **Step 1: Write failing tests** — free table deleted, occupied table refused
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 34: PACK — open an order on a table with covers
- [ ] **Step 1: Write failing tests** — table marked occupied, covers recorded, a second order on the same table is refused
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 35: PACK — move an order between tables
- [ ] **Step 1: Write failing tests** — order moves, source frees, target must be free, the move is logged
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 36: PACK — modifier groups, part 1: list
- [ ] **Step 1: Write failing tests** — empty state, groups with their modifiers
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 37: PACK — modifier groups, part 2: create
- [ ] **Step 1: Write failing tests** — created, min and max selections validated
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 38: PACK — modifier groups, part 3: update
- [ ] **Step 1: Write failing tests** — updated, past order lines keep their modifier snapshot
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 39: PACK — modifier groups, part 4: delete
- [ ] **Step 1: Write failing tests** — unattached group deleted, attached group refused
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 40: PACK — apply modifiers to an order line
- [ ] **Step 1: Write failing tests** — modifiers attach to a line, price deltas apply, min/max enforced, the chit shows them
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 41: PACK — courses and fire
- [ ] **Step 1: Write failing tests**

```php
public function test_a_line_is_assigned_a_course(): void;
public function test_adding_a_line_does_not_fire_it(): void;
public function test_firing_a_course_moves_only_that_courses_lines(): void;
```

The second is the behaviour the whole dining room depends on.

- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 42: PACK — tile-menu POS screen
- [ ] **Step 1: Write failing tests** — dishes render as tiles grouped by course, open tabs shown, a tile adds a line
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 43: PACK — kitchen queue view
- [ ] **Step 1: Write failing tests** — fired lines in fire order by station, touch marks preparing and ready, completed tickets clear on a configurable timer
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 44: PACK — split a bill by amount
- [ ] **Step 1: Write failing tests** — split evenly across N, remainders assigned deterministically, splits sum to the total
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 45: PACK — split a bill by item
- [ ] **Step 1: Write failing tests** — lines assigned to separate bills, every line assigned exactly once, each bill closes independently
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 46: PACK — kitchen chit document
- [ ] **Step 1: Write failing tests** — chit shows items, modifiers and course, no prices, one chit per station
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 47: PACK — table bill and delivery slip documents
- [ ] **Step 1: Write failing tests** — bill shows covers and tax decomposed; the delivery slip shows address and rider, no prices where the shop prefers
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 48: PACK — post a room bill to a customer account
- [ ] **Step 1: Write failing tests** — a room order closes to a ledger charge instead of a counter payment, and requires a customer
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 49: PACK — reports
- [ ] **Step 1: Write failing tests** — dish margin, table turnover, order-type mix, voids by reason
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 50: PACK — seed data
- [ ] **Step 1: Write failing tests** — starter courses and prep stations seeded, no starter menu, idempotent
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

### Task 51: Phase 3 acceptance
- [ ] **Step 1: Full suite green, nothing weakened**
- [ ] **Step 2: Open a table order, fire starters, add mains, fire, mark ready in the kitchen, split the bill, close**
- [ ] **Step 3: Take a delivery order through to delivered**
- [ ] **Step 4: Confirm the kitchen endpoint stays within its query budget under a four-screen poll**
- [ ] **Step 5: `vendor/bin/pint --dirty --format agent`**
