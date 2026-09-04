# Vertical packs

**Phase:** 0 · **Lands in:** Core · **Status:** Planned
**Depends on:** nothing — this is the foundation
**Blocks:** every other page in this folder

## Why

Six trades share one backend. Without a declared contract between them, trade-specific behaviour leaks into shared code as conditionals, and the system becomes six applications sharing a database.

## The rule

> **A vertical pack may know everything about the core. The core may never know that a vertical exists.**

There is no `if ($shop->vertical === 'pharmacy')` anywhere outside a pack's own registration — not in a controller, not in a Blade template, not in a model, not in a query scope.

## The nine seams

A pack is a subclass of the existing `App\Modules\Module`. It may extend the core in these ways and no others.

| # | Seam | Contract |
|---|---|---|
| S1 | Product types | `productTypes(): array` |
| S2 | Entities | tables namespaced by pack key |
| S3 | Order field groups | `orderFieldGroups(): array` |
| S4 | POS screen | `posScreen(): string` |
| S5 | Pricing strategy | `PriceResolver` |
| S6 | Stock strategy | `StockStrategy` |
| S7 | Documents | `documents(): array` |
| S8 | Reports | `reports(): array` |
| S9 | Seed data | `seed(TenantSeedContext): void` |

The list is closed. A pack needing a tenth seam is a signal that something belongs in the core — resolve it through the rule of two, not by widening the contract.

## The rule of two

Build it in the pack the first time. When a second pack needs the same thing, **it moves to the core before that second pack ships**. The ratchet runs one way: capabilities move pack to core and never back.

Already determined by this rule:

- Vehicles, service history, inspections, vehicle compatibility — core in Phase 2 (two automotive packs need them)
- Delivery and kitchen-screen transport — core in Phase 3 (restaurant and mess need them)
- Customer accounts and ledger — core in Phase 1 (every trade needs them)

## Enforcement

Two tests land with this feature and must fail the build, not merely warn:

1. **Dependency direction** — nothing in the core namespace may reference a pack namespace.
2. **No vertical literals** — shared code may not contain a pack key as a string literal.

Without these the rule is a wish. Neither test is expensive to write.

## Invariants

- A pack cannot enable a module the platform entitlement withheld. The platform ceiling always wins.
- A shop with no vertical set resolves to the generic retail pack. This fallback is what makes rollout safe.
- Pack keys are persisted and must never be renamed casually, exactly like module keys today.

## Open questions

- Does a shop get exactly one pack, or several at once? A petrol station with a mini-mart suggests several. Current assumption: several, with one marked primary for the default POS screen.
