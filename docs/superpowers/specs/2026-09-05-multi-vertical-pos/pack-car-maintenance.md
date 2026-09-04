# Car maintenance pack

**Phase:** 2 · **Status:** Planned
**Depends on:** [pack-oil-change](pack-oil-change.md), [order-lifecycle](order-lifecycle.md) *(soft — job cards can ship simpler first)*

## Why it comes third

It is the least new machinery of any trade — mostly a rearrangement of what already exists. It is also the **first real test of the rule of two**, which is why it matters more than its size suggests.

## The rule-of-two obligation

Settled decision: oil change and car maintenance are **two separate packs**. Both need vehicles, service history, inspections and vehicle-to-part compatibility.

> **Those four move from the oil-change pack into the core, and it happens before this pack ships — not after.**

This is the whole discipline in one concrete step. Deferred, it becomes two divergent copies of the same domain, and every future change to vehicles costs twice. If the rule survives here, the architecture holds; if it is skipped here, it will be skipped everywhere.

## What it owns

| Seam | Contribution |
|---|---|
| S1 Product types | `part` (piece, fixed or manual price), `labour_operation` (no stock, priced by standard hours), `consumable` |
| S2 Entities | `job_cards`, `job_card_lines`, `labour_operations`, technician assignment |
| S3 Field groups | Reuses the core vehicle block; adds the job-card block |
| S4 POS screen | **Job-led**: open jobs first, parts and labour added over days, invoice at the end |
| S7 Documents | Estimate, approved job card, final invoice |
| S8 Reports | Technician utilisation, labour versus parts revenue split |

## The workflow that defines it

```
estimate -> customer approval -> work in progress -> invoice
```

The approval step is the point. An estimate that becomes a job without a recorded approval is how workshops get into disputes, so approval is captured — who approved, when, and against which estimate version.

## Labour as a priced thing

Today labour is a free-text `labor_charge` on the sale. Here it becomes a **labour operation**: a named job with standard hours and a rate, so "front brake pads, both sides" prices consistently and reports meaningfully.

## Relationship to the order lifecycle

A job card is the same shape as an open order — work that opens, accumulates lines over hours or days, and invoices at the end. Phase 3 deliberately lands the [order lifecycle](order-lifecycle.md) in the core and lets **job cards use it before the restaurant depends on it**, so the foundation is proven by a trade already understood.

If Phase 2 ships before that, job cards may carry their own simpler state and migrate onto the lifecycle in Phase 3. That is an acceptable order; the reverse is not.

## Open questions

- Can a job card be split across multiple invoices — a deposit then a balance? Common in bigger jobs, and it changes how the close-to-sale transition works.
