# Stock lots

**Phase:** 5 · **Lands in:** Core · **Status:** Planned
**Depends on:** [product-types](product-types.md), [price-book](price-book.md)
**Blocks:** [pack-pharmacy](pack-pharmacy.md)

## The gap today

`items.stock_level` is a single decimal per item. There is no notion of **which delivery** a unit came from, what it cost on that delivery, or **when it expires**.

A pharmacy cannot operate this way. Batch number and expiry are the job, not a nice-to-have, and dispensing must draw from the batch that expires first.

## What it adds

A stock ledger with lots, where **`items.stock_level` is demoted to a derived cache** rather than the source of truth.

- `item_lots` (tenant): item, batch number, expiry date, quantity received, quantity remaining, unit cost for that lot, supplier, received-at.
- `stock_movements` (tenant): item, lot, quantity, direction, reason (`purchase` / `sale` / `adjustment` / `return` / `expiry`), related sale or supply, recorded-by, timestamp.

## Draw-down

Selected by the product type's `stock_mode`:

| Mode | Behaviour |
|---|---|
| `none` | No draw-down. Services, labour, dishes. |
| `simple` | Decrement the item total. Today's behaviour. |
| `lot` | Draw from the lot expiring first, splitting across lots when a line exceeds one lot. |

## What this delivers beyond pharmacy

- **True cost of goods.** Margin today is computed against a single `unit_cost`; with lots it is computed against what that specific unit actually cost. Every trade's reporting improves.
- Expiry for perishables in retail and restaurant kitchens.
- Supplier-wise batch costing, which the existing supplier ledger can then report against.

## Migrating existing data

Every item with stock gets **one opening lot** carrying its current `stock_level` and `unit_cost`, with a null batch number and null expiry. Nothing is lost, and shops that never adopt batches are unaffected.

## Invariants

- `stock_level` is a cache and must always be rebuildable from movements. Any code path that trusts it must be able to recompute it.
- Movements are append-only. A correction is a compensating movement, never an edit.
- A lot's `quantity_remaining` never goes negative. Overselling is a hard error, not a warning, when `stock_mode` is `lot`.
- Expired stock is not silently sellable. It requires an explicit, permissioned, logged override.

## Reporting

- Near-expiry by item and lot, with a configurable horizon.
- Expired stock written off, and its value.
- Return-to-supplier candidates.

## Open questions

- Does the counter pick the lot, or does the system always choose first-expiry-first? Automatic with an override is the usual answer; a pharmacist sometimes needs a specific batch.
- Is there a minimum shelf life at sale — refusing to dispense something expiring in two days? Worth asking a pharmacist before assuming.
