# Oil change pack

**Phase:** 0 · **Status:** Running today, to be extracted into a pack
**Depends on:** [vertical-packs](vertical-packs.md), [pos-screen-composition](pos-screen-composition.md)

## Why it comes first

This is the only trade already running in production (Voltera Garage). Phase 0 does not build it — it **extracts** it, turning today's core-with-automotive-baked-in into a core plus one pack.

That makes it the reference implementation and the acceptance test for the whole seam: if the extraction changes any behaviour for the live shop, the seam is wrong.

## What it owns after extraction

| Seam | Contribution |
|---|---|
| S1 Product types | `oil` (measured, litre), `part` (piece), `service` (no stock, manual price) |
| S3 Field groups | The vehicle block: model, plate, mileage, next-checkup mileage |
| S4 POS screen | Job-led counter with vehicle capture and dispensed quantity |
| S7 Documents | Service invoice with vehicle and next-checkup details |
| S9 Seed data | The oil, parts and repairs catalogue plus reference vehicle makes and models |

## What moves out of it

- **Into the core in Phase 0:** the shared POS components (cart, totals, payment, customer lookup) currently tangled into `pos/create.blade.php`.
- **Into the core in Phase 2:** vehicles, service history, inspections and vehicle-to-part compatibility, once car maintenance becomes a second pack that needs them. See [pack-car-maintenance](pack-car-maintenance.md).

What remains genuinely its own after Phase 2 is narrow: dispensed quantity, next-checkup mileage, and the counter arrangement.

## The columns to move

`sales` currently carries `vehicle_model`, `vehicle_plate`, `mileage` and `next_checkup_mileage`. These are **the one place the core already knows about a trade** and are the pattern to migrate away from — into the vehicle field group, storing against `customer_vehicles`.

This is a data migration on a live shop with real history. Treat it with the care the tenant-adoption work already established: additive first, backfill, verify, then remove.

## Acceptance

- Voltera Garage behaves identically before and after: same screens, same invoices, same reports, same permissions.
- Its existing 11+ expenses, sales history and vehicle records are untouched.
- The full existing test suite passes without weakening an assertion.
