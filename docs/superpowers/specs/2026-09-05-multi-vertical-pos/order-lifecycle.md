# Order lifecycle

**Phase:** 3 · **Lands in:** Core · **Status:** Planned
**Depends on:** [price-book](price-book.md)
**Blocks:** [delivery](delivery.md), [kitchen-screen](kitchen-screen.md), [pack-restaurant](pack-restaurant.md), [pack-mess](pack-mess.md)

## The gap today

`App\Actions\RecordSale` takes a validated payload and writes a complete, paid sale in one transaction. A sale is **born complete**.

A kitchen needs the opposite: an order that opens, is added to over an hour, fires each course as it is ready, and only becomes a paid sale at the end — with **line-level state**, because that state is what a kitchen screen renders.

## The shape

An **order** is the mutable thing in front of the sale. A **sale** stays what it is today: a settled, immutable financial record.

```
order opened -> lines added -> lines fired -> lines ready -> served -> order closed -> sale recorded
```

## Data model

- `orders` (tenant): status, opened-by, opened-at, closed-at, resulting `sale_id` (nullable until closed), plus whatever field groups attach.
- `order_lines` (tenant): item, quantity, price snapshot, status, fired-at, ready-at, notes.

Line status: `pending` / `fired` / `preparing` / `ready` / `served` / `void`.

## Why not statuses on `sales`

Because the discipline that a sale is a settled financial record is worth keeping, and it is the kind of thing that is easy to give up and very hard to get back. Once `sales` rows are mutable, every report has to ask "was this row finished?", and every financial reconciliation gets a caveat.

**Closing an order writes a sale.** That transition is the audited boundary between operational state and financial record.

## Who else benefits

Car maintenance job cards are the same shape — an open piece of work, added to over hours or days, invoiced at the end. Phase 3 deliberately lands the lifecycle first and lets **job cards use it before the restaurant depends on it**, so the foundation is proven by a trade already understood.

Held bills at any counter also fall out of this for free.

## Invariants

- An order that is closed is closed. Re-opening is a new order referencing the old one, never a status flip backwards.
- A voided line is retained with its void reason, never deleted — the kitchen may already have cooked it.
- Stock draws down at the point defined by the product type's stock strategy, not automatically at close.

## Open questions

- Does an open order hold stock (reserve it) or only draw at close? Reserving is correct for a restaurant with limited portions and adds real complexity. Recommend not reserving in Phase 3, and revisiting if it bites.
- How long may an order stay open? An abandoned-order sweep is needed or the open list becomes unusable.
