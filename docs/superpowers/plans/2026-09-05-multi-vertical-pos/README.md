# Multi-Vertical POS — task plans

Task-by-task implementation plans, one file per vertical. Every task is deliberately small: **a CRUD module is four tasks, not one.** An agent should be able to pick up a single task, finish it, get a green test run, and stop.

**Design:** [`../../specs/2026-09-05-multi-vertical-pos-platform-design.md`](../../specs/2026-09-05-multi-vertical-pos-platform-design.md)
**Feature pages:** [`../../specs/2026-09-05-multi-vertical-pos/`](../../specs/2026-09-05-multi-vertical-pos/)

## Files

| Order | File | Builds |
|---|---|---|
| 0 | [Foundation](00-foundation-tasks.md) | The pack seam, product types, pack-scoped migrations, POS components, oil-change extraction |
| 1 | [Retail](01-retail-tasks.md) | Price book, tax, barcodes, variants, customers and ledger, kirana/super-store pack |
| 2 | [Car maintenance](02-car-maintenance-tasks.md) | Vehicles to core, job cards, labour operations, technician assignment |
| 3 | [Restaurant](03-restaurant-tasks.md) | Order lifecycle, delivery, kitchen transport, tables, modifiers |
| 4 | [Mess](04-mess-tasks.md) | Subscriptions, plans, attendance, production sheet |
| 5 | [Pharmacy](05-pharmacy-tasks.md) | Stock lots, salt search, prescriptions, register, returns, panels |

**Do not run these out of order.** Each file builds core pieces the next one assumes.

## Ships before all of this

[Draft sales](../2026-09-05-draft-sales-tasks.md) — the counter holding several bills open at once, one per bay. It depends on nothing and can ship against the current POS immediately. It is deliberately built as `orders` and `order_lines`, which makes it the **first increment of the order lifecycle** that Phase 3 extends.

## Specialist agents

One subagent per vertical, defined in `.claude/agents/`. Each is briefed on its own trade's rules and traps.

| Agent | Covers |
|---|---|
| `pos-draft-sales` | Draft sales on the current POS |
| `pos-foundation` | Phase 0 — the seam |
| `pos-retail` | Phase 1 — kirana and super store |
| `pos-car-maintenance` | Phase 2 — workshop |
| `pos-restaurant` | Phase 3 — restaurant, hotel, KDS |
| `pos-mess` | Phase 4 — mess, canteen, tiffin |
| `pos-pharmacy` | Phase 5 — pharmacy |

## Core versus pack

Core capabilities live in the file of the vertical that **first forces them into existence**, marked `CORE` in the task heading. That is deliberate: an agent working the retail plan builds the price book, then the ledger, then the retail pack, in the order the dependencies actually require.

A task marked `CORE` must not reference any pack. A task marked `PACK` may reference the core freely.

## How to work a task

1. Read the linked feature page for the module before starting its first task.
2. Follow the repository's TDD convention: failing test, verify red, implement, verify green.
3. Run the narrowest test set that covers the change: `php artisan test --compact <path>`.
4. Run `vendor/bin/pint --dirty --format agent` before finishing.
5. Tick the checkbox. Leave the rest alone.

## Shared constraints for every task in every file

- **The dependency rule.** No `if ($shop->vertical === …)` in core code. Ever. Two architecture tests enforce this from Foundation Task 4 onward; do not weaken them.
- **The rule of two.** If a second pack needs something a pack already has, move it to the core *before* the second pack ships. This is scheduled explicitly where it is known (Car maintenance Task 1).
- Use `php artisan make:*` with `--no-interaction` to generate files.
- Tenant models extend `TenantModel`. Central models use the `central` connection.
- Do not change dependencies without approval.
- Every behaviour change gets a focused PHPUnit feature test. Do not weaken an existing assertion to make a new test pass.
- Financial values are decimal snapshots. A historical sale is never recomputed from a current record.
- Uploaded files go to the private disk through `TenantStoragePath`, and are served only through a permissioned controller action.
