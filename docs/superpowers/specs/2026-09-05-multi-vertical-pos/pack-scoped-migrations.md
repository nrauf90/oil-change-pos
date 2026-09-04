# Pack-scoped migrations

**Phase:** 0 · **Lands in:** Core · **Status:** Planned
**Depends on:** [vertical-packs](vertical-packs.md)
**Blocks:** every pack

> **This is the largest single piece of Phase 0 and the one most likely to be underestimated.**

## Why

Settled decision: a shop's database carries only the tables its enabled packs need. A kirana shop has no `customer_vehicles`; a pharmacy has no `inspections`.

The complication is that shop type is also a **soft preset** — a workshop may enable the retail pack later and start selling over the counter. So schemas must be able to grow after provisioning.

## How it works

Each pack owns a migration directory. Enabling a pack on a shop runs that pack's migrations into that tenant's database. Each tenant already has its own `migrations` table, so the storage side is straightforward.

## What has to change

This is where the cost sits, and none of it is optional:

| Component | Change |
|---|---|
| `tenants:migrate` | Computes the expected migration set **per shop** from its enabled packs, rather than one canonical set |
| `tenants:adopt-existing` | Validates an adopted database against that per-shop set |
| `CollectShopHealth` | Reports migration status against the shop's own expected set |
| Platform health badge | "Pending migrations" becomes a per-shop question, not a global one |
| `AdoptExistingTenantTest` | Currently asserts an exact table and migration list; must become per-pack |

## Invariants

- Enabling a pack is **additive only**. It creates tables; it never drops or alters existing ones.
- Disabling a pack does **not** drop its tables. Data survives so that re-enabling is lossless and a mis-click is not destructive.
- Core migrations run for every tenant regardless of packs.
- A pack's migrations are idempotent and re-runnable, exactly as adoption already requires.

## Risks

- **Divergent schemas make every future core migration a question** of which tenants it applies to. Keep the core/pack boundary sharp so core migrations stay universal.
- Health reporting silently going wrong is the likely failure: a shop reporting "current" while missing a newly enabled pack's tables. Test that path explicitly.

## Open questions

- What happens if a pack is enabled while its migrations fail halfway? Provisioning already has a lease and failure-message mechanism; reuse it rather than inventing a second one.
