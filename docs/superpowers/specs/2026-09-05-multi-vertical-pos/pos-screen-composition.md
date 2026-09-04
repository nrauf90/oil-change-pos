# POS screen composition

**Phase:** 0 · **Lands in:** Core (components) + Pack (screens) · **Status:** Planned
**Depends on:** [vertical-packs](vertical-packs.md), [product-types](product-types.md)
**Blocks:** every pack

## Why

Settled decision: **one POS screen per trade**. Each pack owns its own checkout screen rather than composing a shared shell.

The maintenance cost of that is real and worth naming plainly: a checkout bug gets fixed once per trade. This page exists to keep that cost as low as the decision allows.

## The discipline

Everything genuinely shared is extracted into **core Blade components** that each screen composes:

- Cart and line editing
- Totals and charge lines
- Payment capture and split tender
- Customer lookup and selection
- Numeric keypad
- Item search and picker

What a pack owns is **the arrangement and its own capture blocks** — not a private copy of the cart.

> Treat any logic that appears in two pack screens as a core component. This is the rule of two applied at the template level.

## Field groups (seam S3)

A field group is a block of capture that attaches to an order, declaring its inputs, its validation and **its own storage**. The vehicle block writes to `customer_vehicles`; a prescription block writes to `prescriptions`. `sales` gains no columns for either.

Because each pack owns its screen, field groups are what stop that screen re-implementing capture and validation from scratch.

## Starting point

Today's `resources/views/pos/create.blade.php` is 1,582 lines and mixes shell, cart, item picker, vehicle capture and payment. Phase 0 splits it:

- Shared parts become core components
- Vehicle capture becomes the oil-change pack's vehicle field group
- The arrangement becomes the oil-change pack's screen

## Invariants

- A core component must never know which trade is rendering it. **A cart component that knows about prescriptions is the dependency rule being broken in a different place.**
- Field groups are filtered by the permissions the signed-in user actually holds, not merely hidden.

## Open questions

- Alpine state is currently one large component in the template. Whether the shared cart keeps its own Alpine store, or each screen owns state and passes it down, should be settled before the second screen is written — not after.
