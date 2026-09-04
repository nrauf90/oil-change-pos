# Retail pack (kirana and super store)

**Phase:** 1 · **Status:** Planned
**Depends on:** [price-book](price-book.md), [customer-accounts-and-ledger](customer-accounts-and-ledger.md)

## Why it comes second

Not because it is the largest opportunity, but because **it forces the price book and the customer ledger** — the two core pieces four other trades wait on. Shipping it first means those foundations are built by a real shop rather than designed in the abstract.

Kirana and super store are the **same pack**. A super store is a kirana with more departments and more items; the mechanics are identical.

## What it sells

Groceries, kitchen goods, household and washroom items. Packaged goods by the piece, loose goods by weight.

## What it needs that the core will not already have

| Need | Where it lands |
|---|---|
| Fixed sell price, **tax-inclusive** | [price-book](price-book.md) — core, Phase 1 |
| Barcode scan to cart | Core, Phase 1 |
| Variants (pack sizes) | Core, Phase 1 |
| Udhaar (credit ledger) | [customer-accounts-and-ledger](customer-accounts-and-ledger.md) — core, Phase 1 |
| Loose goods by weight | **Already works** — `UnitOfMeasure` supports kilogram with decimal stock |
| Departments / aisles | Pack — a category tree over the catalogue |

## What it owns

| Seam | Contribution |
|---|---|
| S1 Product types | `packaged_good` (piece, barcode, fixed price), `loose_good` (measured, fixed price per unit), `bundle` |
| S4 POS screen | **Scan-led**: barcode field always focused, numeric keypad, running total prominent, minimal chrome |
| S7 Documents | Simple receipt, and a customer statement for udhaar accounts |
| S9 Seed data | A starter category tree. Deliberately no starter catalogue — a kirana's stock is its own |

## The counter that actually matters

The scan-led screen is the whole feature. A kirana cashier scans, scans, scans, takes cash, and moves on — often with a queue. Anything that steals focus from the barcode field is a defect. The current item picker is built for browsing and is the wrong shape entirely.

## Pricing

Shelf prices are **tax-inclusive** — what is marked is what the customer pays. The cashier never adds tax at the till, and the receipt shows the tax decomposed out of the total rather than appended to it. See [price-book](price-book.md) for the arithmetic and its rounding rule.

## Open questions

- Is there a weighing-scale integration, or does the cashier type the weight? Typing is assumed; scale integration is hardware work that is out of scope for now.
- Do they need multi-unit selling — a single sachet and a full carton of the same product? If yes this is variants; if it is common, it deserves testing before Phase 1 closes.
