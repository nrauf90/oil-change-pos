# Subscriptions

**Phase:** 4 · **Lands in:** Core · **Status:** Planned
**Depends on:** [customer-accounts-and-ledger](customer-accounts-and-ledger.md), [order-lifecycle](order-lifecycle.md)
**Blocks:** [pack-mess](pack-mess.md)

## The gap today

Every sale is a **one-off transaction**. There is no concept of a customer who pays monthly for something consumed daily.

A mess is exactly that: a subscriber on a meal plan, billed per cycle, consuming meals that are **marked** rather than rung up, with a balance carried forward.

## The distinction that matters

> **A subscription is an agreement that generates charges against an account. Meals consumed against it are attendance, not transactions.**

Collapsing the two — modelling a mess subscription as a recurring sale — makes every revenue report wrong and is very hard to unpick later. A consumed meal must not create a sale.

## Data model

- `plans` (tenant): name, price per cycle, cycle length (weekly / monthly), **billing mode** (`advance` / `arrears`), what it entitles per period (for example two meals a day, 26 days).
- `subscriptions` (tenant): customer, plan, start date, end date, status (`active` / `paused` / `ended`), price snapshot at signup.
- `subscription_cycles` (tenant): subscription, period start and end, amount charged, the ledger entry it created.
- `consumption_events` (tenant): subscription, date, meal slot, marked-by user. This is the attendance record.

Billing a cycle writes a `charge` to the customer ledger. Payments arrive as ledger `payment` entries exactly like any other.

## Invariants

- A consumption event **never** creates a sale or touches the cash drawer.
- A cycle is billed once. Re-running billing is idempotent per subscription per period.
- A price is snapshotted at signup; changing a plan's price never rewrites an existing subscription's past cycles.
- Pausing a subscription stops future cycles; it does not delete past ones.

## Walk-ins

A walk-in buying a single meal is an ordinary sale through the ordinary counter. The two paths coexist and must be reportable together — total revenue is subscription charges plus walk-in sales, and the report should say so plainly rather than mixing them silently.

## Billing mode — settled

**Both modes are supported, chosen per plan.** Arrears is the default; advance is available where a shop wants it.

| | Arrears (default) | Advance |
|---|---|---|
| Charge written | At cycle **end**, for what was consumed | At cycle **start**, for the full entitlement |
| What the balance means | A **debt** the subscriber owes | A **prepayment** being drawn down |
| Sign convention | Balance goes negative until paid | Balance goes positive at charge, falls as consumed |
| Report that matters | Ageing — who owes, and how long | Depletion — who is about to run out |

Because a customer's balance now means opposite things depending on the plan, **the ledger must never display a bare balance without its mode**. A subscriber "at -4,500" is overdue under arrears and impossible under advance. Store the mode on the subscription, snapshot it there at signup, and have every screen and statement render the balance in the mode's own language.

Both modes share the same `customer_ledger_entries`; only the timing and the sign convention differ.

## Open questions

1. **Do unused meals carry forward or lapse?** Still open, and it interacts with billing mode. Lapsing is far simpler. Carrying forward means tracking entitlement versus consumption per cycle and a rollover balance that itself needs an expiry rule — and under advance billing, a carried-forward meal is a liability the shop still owes. *Recommendation: lapse by default, with carry-forward as a per-plan flag only if a real customer asks.*
2. Is a mid-cycle joiner pro-rated or charged in full?
3. Under arrears, what happens when a subscriber stops attending without notice? A cycle still closes and a charge is still written unless the subscription is ended — an abandoned-subscriber sweep is needed.
