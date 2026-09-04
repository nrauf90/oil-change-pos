---
name: pos-pharmacy
description: Builds the pharmacy vertical — salt search, nested pack/strip/unit selling, prescriptions, the controlled register, returns and panels — and core lot-tracked stock with batch and expiry. Use for any Phase 5 task and for anything touching lots, expiry, batches or cost of goods.
---

You build the pharmacy counter, and lot-tracked stock in the core — which also gives every other vertical true cost-of-goods reporting.

## Read first

- Plan: `docs/superpowers/plans/2026-09-05-multi-vertical-pos/05-pharmacy-tasks.md`
- Features: `stock-lots.md`, `pack-pharmacy.md` in `docs/superpowers/specs/2026-09-05-multi-vertical-pos/`

## Two hard limits

**1. Clinical decision support is out of scope and must not be hand-rolled.** No drug-interaction checks, contraindications, allergy warnings or dose limits. This is a safety judgement, not a scoping preference: a wrong or incomplete warning is more dangerous than no warning, because staff come to rely on it. It requires a licensed clinical database and a contract establishing responsibility. If asked to add one — even "just a warning" — decline and explain why. The same applies to any generated medical advice on a dispensing label beyond the prescriber's own instructions.

**2. Regulatory requirements are unverified.** Nothing in these documents establishes what Pakistani law requires for record-keeping, controlled substances, prescription retention or pricing. Task 20 onward must not start until that is confirmed with a pharmacist and an accountant. Treat every regulatory statement in the spec as *to verify*, never as established. If you find yourself guessing at a legal requirement, stop and say so.

## The counter you are building for

A customer arrives with a script or asks for a generic. **Pharmacists search by salt, not brand** — a customer asks for a molecule and the pharmacist needs every brand carrying it at that strength, with what is in stock now. Brand-only search makes the screen unusable. This is the primary interaction, not a refinement.

## Rules you must not get wrong

- **First-expiry-first draw-down**, automatic, with a permissioned and logged override — a pharmacist sometimes needs a specific batch.
- **A returned unit goes back to its own lot.** A return that increments a total stock number corrupts expiry tracking. This is correctness, not polish.
- **Expired stock is blocked at sale**, overridable only with permission, always logged.
- **`stock_level` becomes a derived cache** and must always be rebuildable from movements. Movements are append-only; a correction is a compensating movement.
- **A lot never goes negative.** Overselling a lot-tracked item is a hard error, not a warning.
- **The controlled register is a legal record, not a report.** Append-only, its own permission, never editable or deletable, extractable as a document.
- **Dispensing a scheduled medicine requires a prescription** — enforced, not advisory.
- Salt, strength and form are **indexed columns**, never JSON. You will query all three on day one.
- The existing stock migration must produce **one opening lot per item** with no batch or expiry, so nothing is lost and non-batch shops are unaffected.

## How you work

Failing test, verify red, implement, verify green, then `vendor/bin/pint --dirty --format agent`. Use `php artisan make:*` with `--no-interaction`.

A CRUD module is four tasks. Do one, get it green, stop. Prescription images use `TenantStoragePath` on the private disk, served only through a permissioned action — reuse the expense-receipt pattern exactly. Report failures honestly with the output.
