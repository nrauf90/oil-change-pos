# Restaurant pack (hotel and dine-in)

**Phase:** 3 · **Status:** Planned
**Depends on:** [order-lifecycle](order-lifecycle.md), [delivery](delivery.md), [kitchen-screen](kitchen-screen.md)

## Why it comes fourth

It carries the largest structural change in the plan — the order lifecycle — and the largest new screen. It also deliberately builds **delivery and the kitchen-screen transport in the core**, so the mess in Phase 4 inherits both rather than forcing a rushed generalisation later.

## What it needs from the core first

| Need | Page |
|---|---|
| Orders that open before they are paid, with line-level state | [order-lifecycle](order-lifecycle.md) |
| Dine-in / takeaway / delivery, address, rider, post-kitchen status | [delivery](delivery.md) |
| Pushing outstanding work to a screen in the kitchen | [kitchen-screen](kitchen-screen.md) |

## What it owns

| Seam | Contribution |
|---|---|
| S1 Product types | `dish` (no stock or recipe-backed, modifiers, prep station), `beverage`, `combo`, `ingredient` (not sellable) |
| S2 Entities | `tables`, `modifier_groups`, `modifiers` |
| S3 Field groups | Table and covers block; delivery block reuses the core one |
| S4 POS screen | **Tile menu**: large touch targets grouped by course, open tabs along the top |
| S7 Documents | Kitchen chit, table bill, delivery slip |
| S8 Reports | Dish margin, table turnover, order-type mix |

## Modifiers

"No onions", "extra shot", "less spicy". A modifier group attaches to a product type; individual modifiers may carry a price delta. Only this trade needs them today, so they stay in the pack — under the rule of two they move to the core the moment a second trade needs them.

## The kitchen view

The restaurant renders a **queue of fired orders** on the shared kitchen transport — what to cook next, in order, by prep station. The mess renders something different on the same plumbing. See [kitchen-screen](kitchen-screen.md).

## Tables and covers

A table is a place an order is attached to, with a cover count. Splitting and merging bills is table work and belongs here. Floor-plan layout (drawing the room) is explicitly **not** in scope for the first version — a list of tables is enough to sell against.

## Open questions

- Do orders need to move between tables? Common, and it is a small feature if designed in and an awkward one if retrofitted.
- Are recipes in scope, so a dish depletes ingredient stock? This is where lot-tracked stock pays off a second time, but it is a large feature on its own. Recommend deferring past Phase 3 unless a customer asks.
- Room service, given "hotel": does a room behave as a table, with the bill posting to a room account rather than settling at the counter? If so it is closer to the customer ledger than to tables.
