# Multi-Vertical POS — feature index

One feature per page. Each page is written to be picked up on its own: why it exists, what it changes, its data model, its contract, and what it depends on.

The whole design and the reasoning behind the phasing live in [`../2026-09-05-multi-vertical-pos-platform-design.md`](../2026-09-05-multi-vertical-pos-platform-design.md). Read that first; these pages assume it.

**Nothing here is built.** `docs/features/` describes shipped behaviour; this folder describes planned behaviour. A page moves to `docs/features/` when its feature ships.

## The mechanism

| Feature | Phase | Page |
|---|---|---|
| The pack contract and the dependency rule | 0 | [Vertical packs](vertical-packs.md) |
| Catalogue typing — how a product knows its rules | 0 | [Product types](product-types.md) |
| Per-vertical schemas, applied on pack enable | 0 | [Pack-scoped migrations](pack-scoped-migrations.md) |
| One screen per trade over shared components | 0 | [POS screen composition](pos-screen-composition.md) |

## Core capabilities

| Feature | Phase | Page |
|---|---|---|
| Sell price and tax class | 1 | [Price book](price-book.md) |
| Customers, credit, udhaar | 1 | [Customer accounts and ledger](customer-accounts-and-ledger.md) |
| Orders that open before they are paid | 3 | [Order lifecycle](order-lifecycle.md) |
| Dine-in, takeaway, delivery | 3 | [Delivery](delivery.md) |
| Pushing outstanding work to the kitchen | 3 | [Kitchen screen](kitchen-screen.md) |
| Plans, cycles, consumption | 4 | [Subscriptions](subscriptions.md) |
| Batch, expiry, first-expiry-first | 5 | [Stock lots](stock-lots.md) |

## Vertical packs

| Pack | Phase | Page |
|---|---|---|
| Kirana and super store | 1 | [Retail pack](pack-retail.md) |
| Oil change (extracted from today's core) | 0 | [Oil change pack](pack-oil-change.md) |
| Car maintenance | 2 | [Car maintenance pack](pack-car-maintenance.md) |
| Restaurant and hotel | 3 | [Restaurant pack](pack-restaurant.md) |
| Mess, canteen, tiffin | 4 | [Mess pack](pack-mess.md) |
| Pharmacy | 5 | [Pharmacy pack](pack-pharmacy.md) |

## Build order

Each phase pays for the next. Nothing in a later phase is started before its dependency lands.

```
Phase 0  vertical-packs · product-types · pack-scoped-migrations
         pos-screen-composition · pack-oil-change
Phase 1  price-book · customer-accounts-and-ledger  -> pack-retail
Phase 2  vehicles/history/inspections to core       -> pack-car-maintenance
Phase 3  order-lifecycle · delivery · kitchen-screen -> pack-restaurant
Phase 4  subscriptions                              -> pack-mess
Phase 5  stock-lots                                 -> pack-pharmacy
```
