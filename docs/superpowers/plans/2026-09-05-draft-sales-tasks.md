# Draft Sales Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the counter hold several sales open at once — one per bay — saving each as a draft, reopening it prefilled, and completing it to a real sale and invoice only when the work is finished.

**Architecture:** Drafts are the **first increment of the order lifecycle**, not a throwaway. A mutable `order` with lines sits in front of the immutable `sale`. Completing an order writes a sale exactly as `RecordSale` does today.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 5, PHPUnit 12, Blade, Alpine.js.

**Related:** [`order-lifecycle.md`](../specs/2026-09-05-multi-vertical-pos/order-lifecycle.md) — Phase 3 extends what this plan builds.

**Depends on:** nothing. This can ship against the current POS immediately.

---

## Brainstorm

**The problem.** A workshop runs four bays at once. Car one is in for an oil change, car two for maintenance plus an oil change. The counter starts a bill for car one, adds the oil and filter, and then car two arrives. Today there is nowhere to put the first bill — it is either finished early or held in someone's head. Neither is acceptable.

**What the counter needs.** Start a bill, save it, start another, come back to the first, add what was actually used, and only then complete and print.

### Build it as the order lifecycle, not as a `draft` flag on `sales`

This is the important call and it is worth the small extra effort now.

- Putting a `draft` status on `sales` makes `sales` rows mutable. Every financial report then has to ask "was this row finished?", and every reconciliation gains a caveat you cannot remove later.
- The multi-vertical plan already needs an order lifecycle in Phase 3 for the kitchen. Building drafts as `orders` now means Phase 3 **extends** this rather than replacing it, and the restaurant work starts on a foundation a real trade has already exercised.
- The shape is identical either way. Choosing `orders` costs one extra table and saves a migration plus a rewrite.

**So: `orders` and `order_lines`, status `draft` then `completed`, closing to a `Sale`.**

### Decisions taken without asking

- **Drafts are visible to the whole counter**, not private to their creator. A workshop counter is shared and the person who opened a bill may be at lunch when the car is ready. Show who created it.
- **An optional label** — bay number, plate, customer name — so four open drafts are distinguishable at a glance. If left blank, fall back to the vehicle plate, then the customer name, then the created time.
- **A draft draws no stock.** Stock moves when the sale is written, exactly as it does now. This also matches the Phase 3 decision that open orders do not reserve.
- **No invoice, no receipt, no sale number at draft stage.** The routes must refuse it, not merely hide the button.
- **Completing a draft runs the same validation as a normal sale.** A draft is allowed to be incomplete; a completed sale is not.
- **Concurrent edit is guarded.** Two counter staff opening the same draft and both saving would silently lose one set of changes. A version check on save, with a clear message, prevents it. This is the single most likely real-world bug in the feature.
- **Deleting a draft is permissioned and logged.** It is not financial history, but it is work someone did.
- **Stale drafts are flagged, never auto-deleted.** A draft open for three days is usually a car still on the ramp.
- Editing a draft loads **customer details if present and items always**. An empty customer block is normal and must not block saving.

**Deliberately excluded:** line-level states, firing, delivery, tables. Those are Phase 3 and belong to the restaurant work.

## Global Constraints

- `sales` gains no status column. A sale stays an immutable financial record.
- Completing an order writes the sale in one transaction; a failed write rolls the whole thing back and leaves the draft intact.
- Every existing POS and checkout test must still pass, unweakened.
- Run `vendor/bin/pint --dirty --format agent` before finishing any task.

---

### Task 1: Orders and order lines storage

**Files:**
- Create: `database/migrations/tenant/*_create_orders_table.php`, `*_create_order_lines_table.php`
- Create: `app/Models/Order.php`, `app/Models/OrderLine.php`
- Create: `database/factories/OrderFactory.php`, `OrderLineFactory.php`
- Test: `tests/Feature/DraftSaleTest.php`

**Interfaces:**
- Produces: `Order::lines(): HasMany`, `Order::user(): BelongsTo`, `Order::sale(): BelongsTo`

- [ ] **Step 1: Generate through Artisan**
- [ ] **Step 2: Write failing tests**

```php
public function test_an_order_holds_lines_with_a_price_snapshot(): void;
public function test_an_order_starts_as_a_draft_with_no_sale(): void;
public function test_an_order_records_who_created_it(): void;
```

- [ ] **Step 3: Verify red**
- [ ] **Step 4: Implement** — `status`, `label` nullable, `customer_name`, `phone`, notes and charge fields mirroring `sales`, `user_id` nullOnDelete, `sale_id` nullable, `version` unsigned integer default 1
- [ ] **Step 5: Verify green and format**

---

### Task 2: Save a draft from the POS

**Files:**
- Create: `app/Actions/SaveDraftOrder.php`, `app/Http/Requests/DraftOrderRequest.php`
- Modify: `app/Http/Controllers/PosController.php`, `routes/web.php`
- Test: `tests/Feature/DraftSaleTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_a_draft_is_saved_from_the_counter(): void;
public function test_a_draft_saves_with_no_customer_details(): void;
public function test_a_draft_saves_with_no_lines(): void;
public function test_a_draft_draws_no_stock(): void;
public function test_the_creator_is_taken_from_the_session_not_the_payload(): void;
```

The third and fourth are the point: a draft is allowed to be incomplete and must not move stock.

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — validation deliberately looser than `StoreSaleRequest`
- [ ] **Step 4: Verify green and format**

---

### Task 3: List open drafts

**Files:**
- Create: `resources/views/orders/index.blade.php`
- Modify: `app/Http/Controllers/OrderController.php`, `routes/web.php`
- Test: `tests/Feature/DraftSaleTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_the_draft_list_loads_with_an_empty_state(): void;
public function test_drafts_are_visible_to_the_whole_counter(): void;
public function test_the_list_shows_the_label_creator_total_and_age(): void;
public function test_completed_orders_are_excluded(): void;
```

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — label falls back to plate, then customer name, then created time
- [ ] **Step 4: Verify green and format**

---

### Task 4: Draft count badge in the navigation

- [ ] **Step 1: Write failing tests** — the badge shows the open draft count, is hidden at zero, and is absent without the permission
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

### Task 5: Reopen a draft in the POS, prefilled

**Files:**
- Modify: `app/Http/Controllers/PosController.php`, the POS Blade template
- Test: `tests/Feature/DraftSaleTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_opening_a_draft_loads_its_lines_into_the_counter(): void;
public function test_opening_a_draft_loads_its_customer_details_when_present(): void;
public function test_opening_a_draft_with_no_customer_leaves_those_fields_empty(): void;
public function test_opening_a_completed_order_is_refused(): void;
```

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — hydrate the existing Alpine cart state from the order
- [ ] **Step 4: Verify green and format**

---

### Task 6: Update an existing draft

- [ ] **Step 1: Write failing tests** — lines are added and removed, customer details change, the creator is never rewritten, a completed order cannot be updated
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

### Task 7: Guard concurrent edits

**Files:**
- Modify: `app/Actions/SaveDraftOrder.php`, `app/Http/Requests/DraftOrderRequest.php`
- Test: `tests/Feature/DraftSaleConcurrencyTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_saving_a_stale_draft_is_refused(): void;
public function test_the_refusal_message_names_who_changed_it(): void;
public function test_a_successful_save_increments_the_version(): void;
```

This is the most likely real-world bug in the feature. Do not skip it.

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — submit the version with the payload, compare, refuse on mismatch
- [ ] **Step 4: Verify green and format**

---

### Task 8: Refuse invoice and receipt on a draft

**Files:**
- Modify: `routes/web.php`, invoice and receipt controllers
- Test: `tests/Feature/DraftSaleTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_the_invoice_route_refuses_a_draft(): void;
public function test_the_pdf_route_refuses_a_draft(): void;
public function test_a_draft_has_no_sale_number(): void;
public function test_the_counter_hides_print_while_a_draft_is_open(): void;
```

The route must **refuse**, not merely hide the button. Hiding is not security.

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement**
- [ ] **Step 4: Verify green and format**

---

### Task 9: Complete a draft to a sale

**Files:**
- Create: `app/Actions/CompleteOrder.php`
- Modify: `app/Actions/RecordSale.php` if needed
- Test: `tests/Feature/DraftSaleCompletionTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_completing_writes_a_sale_from_the_order_lines(): void;
public function test_completing_draws_stock_exactly_once(): void;
public function test_completion_runs_the_full_sale_validation(): void;
public function test_an_incomplete_draft_cannot_be_completed(): void;
public function test_a_completed_order_cannot_be_completed_twice(): void;
public function test_a_failed_sale_write_leaves_the_draft_intact(): void;
```

The last two are what make this safe to use on a busy counter.

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — reuse `RecordSale`; the order links to the sale it produced
- [ ] **Step 4: Verify green and format**

---

### Task 10: Print the invoice after completion

- [ ] **Step 1: Write failing tests** — completing redirects to the invoice, the invoice shows the sale number, the completed order is no longer in the draft list
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

### Task 11: Delete a draft

- [ ] **Step 1: Write failing tests** — a draft is deleted, deletion is permissioned, deletion is written to the activity log, a completed order can never be deleted this way
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

### Task 12: Permissions

**Files:**
- Modify: `app/Enums/Permission.php`, `app/Modules/Features/SalesModule.php`, role seeders
- Test: `tests/Feature/DraftSalePermissionTest.php`

- [ ] **Step 1: Write failing tests**

```php
public function test_a_technician_cannot_see_or_create_drafts(): void;
public function test_a_manager_can_create_and_complete_a_draft(): void;
public function test_only_an_admin_can_delete_a_draft(): void;
public function test_a_guest_is_redirected_from_every_draft_route(): void;
```

- [ ] **Step 2: Verify red**
- [ ] **Step 3: Implement** — follow the existing permission and role conventions exactly
- [ ] **Step 4: Verify green and format**

---

### Task 13: Flag stale drafts

- [ ] **Step 1: Write failing tests** — drafts older than a configurable age are flagged in the list, nothing is auto-deleted, the threshold is configurable
- [ ] **Step 2: Verify red** · **Step 3: Implement** · **Step 4: Green and format**

---

### Task 14: Activity logging

- [ ] **Step 1: Write failing tests** — draft created, completed and deleted are logged; a draft update is logged with what changed
- [ ] **Step 2: Verify red** · **Step 3: Implement** — follow `ExpenseObserver` as the pattern · **Step 4: Green and format**

---

### Task 15: Acceptance

- [ ] **Step 1: Full suite green, nothing weakened**
- [ ] **Step 2: Open four drafts at once, add items to each out of order, complete the second, confirm the others are untouched**
- [ ] **Step 3: Confirm no invoice or PDF is reachable for a draft by URL**
- [ ] **Step 4: Open one draft in two browsers, save both, confirm the second is refused with a clear message**
- [ ] **Step 5: Confirm stock moves once, at completion**
- [ ] **Step 6: `vendor/bin/pint --dirty --format agent`**
