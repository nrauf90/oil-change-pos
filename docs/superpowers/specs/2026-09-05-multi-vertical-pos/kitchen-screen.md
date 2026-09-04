# Kitchen screen

**Phase:** 3 · **Lands in:** Core (transport) + Pack (views) · **Status:** Planned
**Depends on:** [order-lifecycle](order-lifecycle.md)
**Blocks:** [pack-restaurant](pack-restaurant.md), [pack-mess](pack-mess.md)

## The split

Two trades need a screen in the kitchen, and they need **different screens over the same plumbing**:

| | Restaurant | Mess |
|---|---|---|
| Shows | A queue of fired orders | A production sheet |
| Answers | "What do I cook next?" | "How many portions tonight?" |
| Driven by | Open orders | Subscriber counts and the day's menu |

So: the **transport** — pushing outstanding work to a screen and marking it done — is core. **What is rendered** differs and stays in each pack.

## Transport

Settled decision: **short polling.** The screen asks a small open-work endpoint every few seconds.

This is a hosting constraint rather than a preference. Production is shared cPanel hosting with no way to run a long-lived process, so a websocket server is not available without moving hosts. Polling is unglamorous, needs no new infrastructure, and is adequate for a single kitchen.

Revisit only if a real kitchen reports lag. The escalation path is a hosted push service first, a VPS with Reverb second.

## What the transport provides

- An endpoint returning outstanding work for a station, cheap enough to call every few seconds.
- Marking a line `preparing` / `ready` from the kitchen screen, which flows back to the counter.
- **Prep stations**, so a large kitchen routes grill separately from cold service.

## Data model

- `prep_stations` (tenant): name, sort order.
- `order_lines` gains `prep_station_id` (nullable), derived from the item's product type attributes.

## Invariants

- The kitchen screen is a **display and a state-changer, not a till**. It must never be able to change prices or close an order.
- It needs its own permission. Kitchen staff should not hold counter permissions merely to see what to cook.
- Polling must be cheap. A slow endpoint called every three seconds by four screens is a self-inflicted outage on shared hosting — index for it and keep the payload small.

## Open questions

- Does the screen need a bump-bar or touch? Touch is assumed. A cheap Android tablet on the wall is the realistic device.
- How long does a completed ticket stay visible before clearing? Needs to be configurable; kitchens disagree strongly about this.
