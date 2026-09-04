---
name: pos-draft-sales
description: Adds draft sales to the existing POS so the counter can hold several bills open at once — one per bay — reopen them prefilled, and complete them to a real sale and invoice only when the work is done. Use for anything about draft sales, held bills, parked orders, multiple concurrent vehicles at the counter, or "save for later" at checkout.
---

You add draft sales to the POS that is running today. This is the one feature the live workshop is missing most.

## Read first

- Plan: `docs/superpowers/plans/2026-09-05-draft-sales-tasks.md`
- Context for the shape you are building: `docs/superpowers/specs/2026-09-05-multi-vertical-pos/order-lifecycle.md`

## The problem you are solving

A workshop runs several bays at once. Car one is in for an oil change; car two arrives for maintenance plus an oil change. The counter starts a bill for car one, adds the oil and filter, then has to serve car two. Today there is nowhere to put the first bill — it gets finished early or held in someone's head.

The counter needs to start a bill, save it, start another, come back to the first, add what was actually used, and only then complete and print.

## The architectural call — do not shortcut it

**Build drafts as `orders` and `order_lines`, not as a `draft` status on `sales`.**

Putting a status on `sales` makes sale rows mutable, and then every financial report has to ask "was this row finished?" That caveat cannot be removed later. The multi-vertical plan already needs an order lifecycle in Phase 3 for the restaurant kitchen; building drafts as orders now means that phase **extends** this instead of replacing it. The shape is identical either way — one extra table now saves a migration and a rewrite later.

`sales` gains no status column. A sale stays an immutable financial record.

## Rules you must not get wrong

- **A draft draws no stock.** Stock moves when the sale is written, exactly as it does today, and exactly once.
- **No invoice, no receipt, no sale number while a draft is open.** The routes must *refuse* it — hiding the button is not security, and there is a test asserting the route refuses.
- **Completing runs the full sale validation.** A draft is allowed to be incomplete; a completed sale is not.
- **A failed sale write leaves the draft intact.** One transaction; roll the whole thing back on failure. A counter that loses a bill on a hiccup will not be trusted again.
- **Guard concurrent edits.** Two staff opening the same draft and both saving would silently lose one set of changes. A version check on save, with a message naming who changed it. This is the most likely real-world bug in the feature — do not skip Task 7.
- **Drafts are visible to the whole counter**, not private to their creator, with the creator shown. The person who opened a bill may be at lunch when the car is ready.
- **The creator comes from the session, never the payload** — same rule the expense and sale code already follows.
- Deleting a draft is permissioned and logged. Stale drafts are flagged, never auto-deleted — a three-day-old draft is usually a car still on the ramp.
- Reopening loads customer details **if present** and items **always**. An empty customer block is normal and must never block saving.

## Scope

In: save, list, reopen prefilled, update, complete to sale, print after completion, delete, permissions, stale flagging, activity logging.

Out: line-level states, firing to a kitchen, delivery, tables. Those are Phase 3 of the multi-vertical plan and belong to `pos-restaurant`.

## How you work

Failing test, verify red, implement, verify green, then `vendor/bin/pint --dirty --format agent`. Use `php artisan make:*` with `--no-interaction`.

Do one task, get it green, stop. Every existing POS and checkout test must still pass, unweakened — if one fails, fix your change rather than the assertion. Report failures honestly with the output.
