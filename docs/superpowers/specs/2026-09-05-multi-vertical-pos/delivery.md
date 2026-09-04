# Delivery

**Phase:** 3 · **Lands in:** Core · **Status:** Planned
**Depends on:** [order-lifecycle](order-lifecycle.md), [customer-accounts-and-ledger](customer-accounts-and-ledger.md)
**Blocks:** [pack-restaurant](pack-restaurant.md), [pack-mess](pack-mess.md)

## Why this is core, not the restaurant pack

Both the restaurant and the mess deliver. Under the rule of two, a capability needed by a second pack moves into the core **before that second pack ships** — so delivery is built in the core during Phase 3 and inherited by the mess in Phase 4.

Building it inside the restaurant pack and generalising later, under time pressure, is exactly the failure the rule exists to prevent.

## What it adds

- **Order type** on every order: `dine_in` / `takeaway` / `delivery`. Not every trade uses all three; the type is what a pack's screen offers.
- A **delivery address** on the order, drawn from the customer record where one exists.
- A **rider or driver** assigned to the order.
- A status track that **continues after the kitchen is done**: `ready` -> `out_for_delivery` -> `delivered` (or `failed`, with a reason).

## Data model

- `orders` gains `order_type`, `delivery_address`, `rider_id` (nullable), `dispatched_at`, `delivered_at`.
- `riders` (tenant): name, phone, active flag. Deliberately not a `users` row — a rider usually does not sign in.
- Optionally `delivery_charges` as a charge line on the order, so delivery fees are visible in reporting rather than folded into the total.

## Invariants

- A delivery order cannot be closed to a sale until it is `delivered` or `failed`. Money and the goods must not disagree.
- A failed delivery keeps its reason and remains reportable. Repeated failures at one address are something the owner needs to see.
- The delivery charge is a line on the order, never silently added to an item price.

## Reporting

- Orders by type, so the owner sees the dine-in / takeaway / delivery mix.
- Delivery time from `ready` to `delivered`, per rider.
- Failed deliveries by reason.

## Open questions

- Does a rider carry cash and settle at end of shift? If so this touches the cash drawer, and the settlement is its own reconciliation screen — closer to the existing cash-drawer feature than to delivery itself.
- Are third-party platforms (Foodpanda and similar) in scope? Assumed **no** for now. If they arrive later, they are a channel on the order, not a new order type.
