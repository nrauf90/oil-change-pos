---
name: pos-car-maintenance
description: Builds the car-maintenance vertical — job cards, estimates and approval, labour operations, technician assignment — and first moves vehicles, service history and inspections from the oil-change pack into the core. Use for any Phase 2 task and for anything touching the automotive domain.
---

You build the workshop counter. Before you build anything, you pay a debt.

## Read first

- Plan: `docs/superpowers/plans/2026-09-05-multi-vertical-pos/02-car-maintenance-tasks.md`
- Feature: `docs/superpowers/specs/2026-09-05-multi-vertical-pos/pack-car-maintenance.md`

## Task 1 is not optional

Two automotive packs now need vehicles, service history, inspections and vehicle-to-part compatibility. **Under the rule of two, those four move into the core before this pack ships — not after.**

This is the first real test of the architecture. If the move happens cleanly here, the design holds. If it is skipped, the codebase gets two divergent copies of the same domain and every future vehicle change costs twice. **Do not start pack work while automotive tables still belong to the oil-change pack.**

The live oil-change shop must behave identically after that move. Verify against a pre-migration capture; any difference is a defect.

## The workshop you are building for

A car arrives with a complaint. Someone writes an estimate. The customer approves — often only part of it. Work happens over hours or days, parts get added, a technician owns the job. It invoices at the end, sometimes against a deposit already taken.

## Rules you must not get wrong

- **Approval is captured, never assumed** — who, when, and against which estimate version. An estimate that silently becomes a job is how workshops end up in disputes.
- **Estimates are versioned.** Adding work after approval creates a revision needing its own approval. The earlier version is retained.
- **Partial approval is normal.** Declined lines stay on the card as declined — they are the next visit's upsell and they protect the workshop if the car comes back.
- **Labour is a priced operation** with standard hours and a rate, not free text.
- **One technician owns a job**; helpers are recorded separately. Shared ownership makes utilisation reporting meaningless.
- A job card is operational state. **The sale it produces stays an immutable financial record.**
- Vehicle-in and vehicle-out photos use `TenantStoragePath` on the private disk, served only through a permissioned action — the same pattern as expense receipts.

## How you work

Failing test, verify red, implement, verify green, then `vendor/bin/pint --dirty --format agent`. Use `php artisan make:*` with `--no-interaction`.

A CRUD module is four tasks. Do one, get it green, stop. No core code may reference a pack. Report failures honestly with the output.
