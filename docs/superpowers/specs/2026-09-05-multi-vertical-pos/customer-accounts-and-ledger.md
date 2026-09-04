# Customer accounts and ledger

**Phase:** 1 · **Lands in:** Core · **Status:** Planned
**Depends on:** [price-book](price-book.md)
**Blocks:** [subscriptions](subscriptions.md), [pack-retail](pack-retail.md), [pack-mess](pack-mess.md)

## Why

There is no customer entity today. `customer_vehicles` holds a name and phone against a vehicle, and a sale stores a name and phone as loose text. That is enough for a walk-in workshop and not enough for anything else.

Every one of the six trades needs a customer record, and two need a running balance:

- **Kirana** — udhaar. Near-universal in Pakistani retail and completely absent today.
- **Mess** — a subscriber with an account, charged per cycle, paying against a balance.

> Build this properly in Phase 1, not as a kirana bolt-on. [Subscriptions](subscriptions.md) sit directly on top of it in Phase 4, and retrofitting a ledger then is the expensive path.

## Data model

- `customers` (tenant): name, phone, optional address, notes, active flag.
- `customer_ledger_entries` (tenant): customer, type (`charge` / `payment` / `adjustment`), amount, currency-safe decimal, related sale or subscription, recorded-by user, timestamp, note.

The balance is the sum of the ledger, cached on `customers` for listing performance and always recomputable from the entries.

`customer_vehicles` gains an optional `customer_id`, so the automotive packs migrate from loose text to a real customer without losing history.

## Invariants

- **The ledger is append-only.** A mistake is corrected with a compensating `adjustment` entry, never by editing or deleting a row. This matches how `activity_logs` already behaves.
- A cached balance is a cache. Any code path that trusts it must be able to rebuild it from entries.
- Recording a payment is a permissioned action, separate from making a sale — taking money against a balance is not the same authority as ringing up a bill.
- A credit sale creates both a sale and a `charge` entry, in one transaction.

## Reporting

- Outstanding balances by customer, with ageing (30 / 60 / 90 days).
- Who has not paid, sorted by amount and by age — the report a kirana owner actually opens.

## Open questions

- Is there a credit limit per customer, and does exceeding it block a sale or only warn? Blocking is safer; warning is what most small shops actually want.
- Do customers get a printed statement? If so this is a document (seam S7) shared by retail and mess.
