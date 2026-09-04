# Pharmacy pack

**Phase:** 5 · **Status:** Planned
**Depends on:** [stock-lots](stock-lots.md), [price-book](price-book.md), [customer-accounts-and-ledger](customer-accounts-and-ledger.md)

## Why it comes last

It carries the largest data change in the plan, and it is the one trade where **a wrong data model is a compliance problem rather than an inconvenience**. It should inherit a stock ledger that two other trades have already exercised, not be the first user of it.

## What it needs from the core first

| Need | Page |
|---|---|
| Batch, expiry, per-lot cost, first-expiry-first draw-down | [stock-lots](stock-lots.md) |
| Fixed sell price, tax-inclusive | [price-book](price-book.md) |
| Customer records, credit, statements | [customer-accounts-and-ledger](customer-accounts-and-ledger.md) |

Without lot-tracked stock, nothing on this page is possible.

## Seam contributions

| Seam | Contribution |
|---|---|
| S1 Product types | `medicine` (`stock_mode: lot`), `otc`, `surgical`, `cold_chain`, `service` |
| S2 Entities | `prescriptions`, `prescription_lines`, `controlled_register_entries`, `sale_returns`, `panels` |
| S3 Field groups | Prescription block: prescriber, patient, date, image of the script |
| S4 POS screen | **Search-led**: brand *and* salt search, batch visible on every line |
| S5 Pricing | `fixed` from the price book, keyed to the printed pack price |
| S6 Stock | `lot` — first-expiry-first with an audited override |
| S7 Documents | Dispensing label, invoice, register extract, return note |
| S8 Reports | Near-expiry, expired write-offs, returns, register extract, panel billing |

---

## The catalogue

### Salt, strength, form

A medicine record carries **generic (salt) name, strength, and dosage form** alongside the brand. These are indexed columns, not JSON — every one of them is searched or filtered on.

```
Brand: Panadol        Salt: Paracetamol
Strength: 500 mg      Form: tablet
Manufacturer: GSK     Schedule: (see controlled substances)
```

### Search by salt is the core interaction

Pharmacists search by molecule, not brand. A customer asks for a generic and the pharmacist needs **every brand carrying that salt at that strength, with what is in stock right now**.

> Brand-only search makes the counter unusable. This is not a refinement — it is the primary interaction, and the screen should be designed around it.

### Substitution

Having found the salt, the counter offers alternatives: same salt, same strength, different brand, ranked by what is in stock and not near expiry. Substitution is recorded on the sale — what was asked for, what was given.

### Pack, box, strip, tablet

A customer buys six tablets from a strip of ten, out of a box of twenty strips. The catalogue must express that hierarchy, the counter must sell at **any** level, and stock must draw down at the smallest.

The existing pack machinery (`pack_label`, `units_per_pack`, `measure_per_unit`) was designed for cartons of oil and handles two levels. Pharmacy needs three. This is a real extension to the core unit model, not a pack-local concern — expect it to graduate to core under the rule of two the moment a second trade sells in nested units.

### Price is printed on the pack

Medicine prices in Pakistan are typically fixed and printed on the pack rather than set by the shop. That fits `pricing_mode: fixed` exactly, with two consequences: a price override at the counter should be **more** restricted here than in retail, and a price change is a catalogue event worth logging, since it usually comes from the manufacturer rather than the owner.

*Confirm the current regulatory position before relying on this — see the compliance note below.*

---

## Batch and expiry at the counter

- Every line shows its **batch number and expiry**. A pharmacist checks this by habit and its absence is immediately noticed.
- Draw-down is **first-expiry-first**, automatic, with an audited override for when a specific batch is needed.
- **Expired stock is not silently sellable.** Selling it requires an explicit, permissioned, logged override — or a hard block, per the compliance answer.
- **Minimum shelf life at sale**: a configurable rule refusing or warning on stock expiring within N days. Ask a pharmacist for the sensible default rather than inventing one.

## Purchasing and returns

- Goods are received **by batch**: batch number, expiry, quantity, per-unit cost. The existing supplier ledger already models deliveries and payments; this extends its lines with lot detail.
- **Sale returns** are common and must be first-class: the returned unit goes back to *its own lot* so expiry stays correct, and the ledger records the refund. A return that silently increments a total stock number is a bug that corrupts expiry tracking.
- **Return to supplier** for near-expiry stock, with a report of candidates.
- **Write-off** for expired stock, recording the value lost.

## Prescriptions

Capturing the script is straightforward and reuses machinery that already exists: prescriber, patient, date, and an image stored on the private disk exactly as supplier bills and expense receipts are today.

A prescription links to the sale that dispensed against it, so "what was dispensed on this script" is answerable.

**Refills and chronic patients:** a repeat customer on long-term medication is the pharmacy's most valuable relationship. Recording that a patient is on a recurring medication enables a refill-due view. This sits on the customer record, and is the one place this pack touches the customer ledger for more than credit.

## Controlled substances

A **legal record, not a report.** Treat it as append-only, exactly like `activity_logs`: what was dispensed, quantity, to whom, against which prescription and prescriber, by which member of staff, when.

- Never editable, never deletable, corrections only by compensating entry.
- Dispensing a scheduled medicine requires a prescription — enforced, not advisory.
- Its own permission, held by fewer people than ordinary dispensing.
- Extractable as a document for inspection.

**The required fields, the schedules, and the retention period must be confirmed with a pharmacist before this is designed in detail.** They are exactly the kind of constraint that is expensive to retrofit, because they change what may be edited and what must be retained.

## Panel and insurance billing

Where a patient's employer or insurer pays part of the bill, the sale splits between patient and payer. Model it as:

- `panels` (tenant): the paying organisation, its terms, and its discount or coverage rule.
- A sale carries an optional panel, and the split lands as two settlements — cash from the patient, a receivable against the panel.
- The panel receivable uses the **same customer ledger** as udhaar; a panel is a customer that happens to be an organisation.
- A monthly panel statement is the document the pharmacy sends to get paid.

Include this only if a real customer needs it — it is a substantial addition and it is the piece most likely to be specified wrongly from a distance.

## Cold chain

Insulin, vaccines and similar need temperature-controlled storage. A `cold_chain` product type flags them so they are visibly separated on stock reports and on the dispensing label. Actual temperature logging is hardware work and is out of scope.

---

## What this pack deliberately does not do

**Clinical decision support — drug interactions, contraindications, allergy checks, dose limits — is out of scope, and should not be hand-rolled.**

This is a safety issue, not a scoping preference. A wrong or incomplete interaction warning is more dangerous than no warning, because staff come to rely on it. If this is ever wanted, it requires a licensed clinical database maintained by a body qualified to publish it, with a contract that says who is responsible when it is wrong. Do not approximate it from a spreadsheet, and do not let it be added quietly as "just a warning".

The same caution applies to anything that reads as medical advice on a printed label beyond the prescriber's own instructions.

---

## Compliance caveat

**This document does not establish what Pakistani law requires of a retail pharmacy.** Record-keeping, controlled-substance handling, prescription retention, pricing rules and invoicing requirements must all be confirmed with a pharmacist and an accountant before this pack is designed in detail.

The answers may add required fields, may change what can be edited or deleted, and may change how long records must be kept — all of which are far cheaper to design in than to retrofit. Treat the regulatory specifics in this page as *things to verify*, never as *things established*.

## Reports

- Near-expiry by item, lot and horizon
- Expired write-offs and their value
- Return-to-supplier candidates
- Sale returns by reason
- Controlled-substance register extract
- Panel receivables and monthly statements
- Refills due
- Fast and slow movers by salt, which is what drives purchasing

## Open questions

- Does the counter pick the lot, or is first-expiry-first automatic with an override? Automatic with override is the usual answer.
- What is the minimum shelf life at sale, and is breaching it a warning or a block?
- Are panels in scope for the first version, or deferred until a customer asks?
- Is a printed dispensing label required on every item, or only on loose or repackaged quantities?
