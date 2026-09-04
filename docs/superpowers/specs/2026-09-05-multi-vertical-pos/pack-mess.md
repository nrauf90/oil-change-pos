# Mess pack (canteen and tiffin)

**Phase:** 4 · **Status:** Planned
**Depends on:** [subscriptions](subscriptions.md), [delivery](delivery.md), [kitchen-screen](kitchen-screen.md)

## Why it comes fifth

It reuses delivery and the kitchen screen built in Phase 3, which is exactly why it follows the restaurant rather than preceding it. Taking the mess first would mean building both for one trade and then generalising them under pressure.

## What makes it different from a restaurant

A restaurant sells meals to whoever walks in. A mess sells a **plan** to a subscriber who then eats daily.

| | Restaurant | Mess |
|---|---|---|
| Revenue | Per transaction | Per billing cycle |
| Customer | Mostly anonymous | Known subscriber with an account |
| Menu | Standing, chosen from | Rotating by day, mostly fixed |
| Kitchen question | "What do I cook next?" | "How many portions tonight?" |
| Consumption | A sale | Attendance |

That fourth row is why the kitchen view differs, and the fifth is why [subscriptions](subscriptions.md) exist as a core feature.

## What it owns

| Seam | Contribution |
|---|---|
| S1 Product types | `meal_plan` (`pricing_mode: plan`), `daily_dish` (not separately sold to subscribers), plus ordinary sellable items for walk-ins |
| S2 Entities | `menu_cycles` (what is served on which day and meal slot), attendance marking |
| S4 POS screen | **Subscriber-led**: find the subscriber, mark attendance, take a payment against the balance. Walk-ins fall through to an ordinary sale |
| S7 Documents | Subscriber statement, monthly invoice |
| S8 Reports | Subscribers active and lapsed, expected versus actual attendance, cost per meal |

## The kitchen view

A **production sheet**, not an order queue: for tonight's dinner slot, how many portions of each dish, derived from active subscriber counts and the day's menu, plus any walk-in orders already taken. Same core transport, different rendering.

## Walk-ins

A walk-in buying a single meal is an ordinary sale through the ordinary counter. Both paths must be reportable together, and the report should state subscription revenue and walk-in revenue **separately** rather than silently mixing them.

## Billing — settled

**Both arrears and advance, chosen per plan.** Arrears is the default. See [subscriptions](subscriptions.md) for the mechanics and the sign-convention trap.

The practical consequence for this pack: the subscriber screen shows a balance that means *"owes 4,500"* on an arrears plan and *"has 4,500 of credit left"* on an advance plan. Those must never look the same. The screen renders the mode's own language, and the monthly statement does too.

Under arrears the ageing report is the one the owner opens daily; under advance it is the depletion report — who is about to run out and needs collecting from.

## Open questions

1. **Do unused meals carry forward or lapse?** Still open. Recommendation: lapse by default, carry-forward as a per-plan flag only if a customer asks. Under advance billing a carried-forward meal is a liability the shop still owes, which makes it more than a counting exercise.
2. Is a mid-cycle joiner pro-rated or charged in full?
3. Is attendance marked per person at the counter, or assumed present unless marked absent? Assume-present is far less work at a busy serving window and is how most messes actually run.
