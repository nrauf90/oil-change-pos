# Price book

**Phase:** 1 · **Lands in:** Core · **Status:** Planned
**Depends on:** [product-types](product-types.md)
**Blocks:** [pack-retail](pack-retail.md), [pack-restaurant](pack-restaurant.md), [pack-mess](pack-mess.md), [pack-pharmacy](pack-pharmacy.md)

> **The highest-leverage change in the whole plan.** Four of the six trades cannot sell anything until it exists.

## The gap today

An item carries `unit_cost` — **what you paid for it** — and nothing else. Every line on every bill is priced by hand through `sale_items.manually_charged_price`.

That is exactly right for a workshop where each job is negotiated. It is completely wrong for a shop where you scan a barcode and the price is already decided.

## What it adds

- A **sell price** on the item, which `pricing_mode: fixed` reads from.
- A **tax class** (nullable). Settled decision: model the class now, defer any fiscal integration. Retrofitting tax through the price book and every historical sale later is the painful path.
- **Tax-inclusive pricing by default.** Settled decision: `sell_price` is what the customer pays, tax included — the shelf price. Tax is *derived out* of it for reporting, not added on at the till.
- Room for **price tiers** (retail / wholesale) and **price history**, so a past sale is never re-explained by a current price.

## Data model

- `items` gains `sell_price` and `tax_class_id` (both nullable — a `manual` product type still prices by hand).
- `tax_classes` (tenant): name, rate, whether the rate is inclusive or exclusive.
- `price_changes` (tenant): item, old price, new price, who changed it, when. Append-only.

Optionally, and only if a real customer needs it: `price_tiers` and a per-customer tier assignment.

## Interaction with `pricing_mode`

| Mode | Where the price comes from |
|---|---|
| `fixed` | The item's `sell_price`. Cashier may be permitted to override, and the override is recorded. |
| `manual` | Typed at the counter, exactly as today. |
| `recipe` | Computed from component costs plus a margin. |
| `plan` | From a subscription plan per cycle, not per line. See [subscriptions](subscriptions.md). |

## Tax-inclusive arithmetic

The shelf price is the truth. For a line at price `P` with rate `r`:

```
net = P / (1 + r)
tax = P - net
```

Three consequences worth building in from the start, because each is a classic source of one-rupee discrepancies:

- **Round once, at the line, then sum.** Deriving tax per line and summing gives a different total from deriving it on the sum. Pick per-line and hold to it everywhere — counter, invoice, and report.
- **The customer-facing total never moves.** Quantity times shelf price is what they pay. Net and tax are a decomposition shown on the invoice, never a recalculation of the total.
- **A rate change must not alter a past invoice.** Snapshot both the rate and the derived tax amount on the line.

An exclusive-pricing mode may be added later as a per-shop setting if a customer needs it, but inclusive is the default and the only mode built now.

## Invariants

- **A historical sale is never recomputed from a current price.** `sale_items` already snapshots `item_name`; it must snapshot the price, the tax rate and the derived tax amount too. This rule exists in the codebase today and must survive.
- A price override at the counter is a permissioned action and is recorded in the activity log.
- Tax is stored per line as an amount, not only as a rate, so a later rate change cannot rewrite old invoices.

## Open questions

- Does the cashier see margin at the counter? There is already a `ViewItemUnitCost` permission gating cost visibility; the same gate should cover margin. Note that with inclusive pricing, margin must be computed against the **net** price, not the shelf price, or every margin figure is overstated by the tax rate.
