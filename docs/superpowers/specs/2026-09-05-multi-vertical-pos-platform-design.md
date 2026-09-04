# Multi-Vertical POS Platform Design

## Objective

Extend the existing multi-tenant POS so that one backend serves six unrelated trades without forking the codebase per trade and without the shared core accumulating knowledge of any individual trade.

| Trade | Counter it needs |
|---|---|
| Kirana / super store | Barcode retail: groceries, kitchen goods, household and washroom items |
| Oil change | Running today |
| Car maintenance | Job cards, labour operations, technician assignment |
| Restaurant / hotel | Tables, **dine-in / takeaway / delivery**, kitchen display |
| Mess / canteen | Subscribers on monthly meal plans, rotating menu, production planning |
| Pharmacy | Batch and expiry, salt search, pack→strip→tablet, prescriptions |

A single platform admin creates a shop, selects its vertical, and that choice converts the counter into the right POS for that trade.

The open question is not what each trade needs. It is the mechanism: **where generic stops and niche starts**, so that adding a pharmacy does not quietly degrade the kirana counter.

## Scope

This design covers the extension-point contract between the commerce core and per-trade packs, the runtime resolution order, the catalogue typing model, the governance rule that decides when a capability graduates from a pack into the core, and the three gaps in the current core that block new trades.

It does not cover platform billing of shops, custom-domain automation, hardware integration (scales, cash drawers, label printers), or any fiscal or regulatory integration. It *does* cover subscription billing of a shop's own customers, which the mess trade requires. It assumes the tenancy foundation described in `2026-09-01-saas-multi-tenant-foundation-design.md` is unchanged.

## What Already Generalizes

The tenancy and feature-gating machinery needs no rework and this design spends nothing on re-inventing it.

- **Isolated tenant databases.** Each shop is provisioned or adopted with its own database.
- **A pluggable module system.** `App\Modules\Module` has each feature declare its own key, permissions, navigation, dependencies and default state.
- **Two-layer feature control.** The platform sets a per-shop ceiling in `shop_features` (central); the tenant toggles within it via the `modules` table. `module:*` middleware genuinely removes routes when a module is off.
- **Measured stock.** `UnitOfMeasure` already covers piece, litre and kilogram with decimal stock, so loose goods sold by weight need no new concept.
- **Cross-trade modules.** Expenses, cash drawer, suppliers and purchases, activity log, roles and permissions, and reporting all carry over unchanged.

The catalogue is also closer to generic than it appears. `ItemType` is only `Product` and `Repair`, and every automotive field on a sale (`vehicle_model`, `vehicle_plate`, `mileage`, `next_checkup_mileage`) is nullable. Nothing in the sale path requires a vehicle.

## Architecture Decision

### The dependency rule

One structural rule governs everything below it:

> **A vertical pack may know everything about the core. The core may never know that a vertical exists.**

Concretely there is no `if ($shop->vertical === 'pharmacy')` anywhere outside a pack's own registration — not in a controller, not in a Blade template, not in a model. The moment that conditional appears in shared code the system stops being a platform and becomes several applications sharing a database, where every change must be reasoned about once per trade.

Knowledge flows in one direction only:

```
Core     catalogue · pricing · stock · orders · sales · cash · reports
  ^      knows nothing below it
Seams    nine declared extension points
  ^      the only vocabulary a pack may speak
Packs    automotive · kirana · restaurant · pharmacy

Core -> Pack   forbidden. This arrow must never exist.
```

This is enforced, not merely intended. Two tests land in Phase 0: one failing the build if anything in the core namespace references a pack namespace, and one failing if shared code contains a vertical key as a string literal.

### The nine seams

A vertical pack is a subclass of the existing `Module`. It may extend the core in these nine ways and no others. The list being closed is the point: a pack needing a tenth seam is a signal that something belongs in the core, resolved through the rule of two below rather than by quietly widening the contract.

| # | Seam | Contract | Purpose |
|---|------|----------|---------|
| S1 | Product types | `productTypes(): array` | What kinds of thing this trade sells, and which fields each asks for |
| S2 | Entities | tables namespaced by pack key | Tables only this trade uses. Never a column added to a core table |
| S3 | Order field groups | `orderFieldGroups(): array` | Capture blocks attached to an order, each storing to its own entity |
| S4 | POS screen | `posScreen(): string` | The pack's own checkout screen, composed from shared core components |
| S5 | Pricing strategy | `PriceResolver` | Fixed from the book, typed by hand, from a recipe, or from labour hours |
| S6 | Stock strategy | `StockStrategy` | No draw-down, simple decrement, or first-expiry-first across lots |
| S7 | Documents | `documents(): array` | Invoice, kitchen chit, job card, dispensing label |
| S8 | Reports | `reports(): array` | Named reports contributed to the shared reporting shell |
| S9 | Seed data | `seed(TenantSeedContext): void` | Starting catalogue for a new shop of this trade |

**S3 does the most work.** It is what lets a sale stay generic while five trades capture wildly different things against it. A field group declares its inputs, its validation and its own storage, so the vehicle block writes to `customer_vehicles` and a prescription block writes to `prescriptions`, while `sales` gains no columns for either. Because each pack owns its own screen (S4), field groups are what stop that screen having to re-implement capture and validation from scratch.

Today's automotive columns on `sales` are the pattern to migrate *away* from — they are the one place the core already knows about a trade.

## Runtime Resolution

"Which POS does this shop get?" must have one deterministic answer, in one resolver, with no special cases.

1. The tenant is resolved from the host, exactly as today. Nothing changes here.
2. The shop's `vertical` selects its pack. A shop with no vertical set gets the **generic retail pack** — that fallback is what makes the rollout safe.
3. Enabled modules are read as they are now: platform entitlement first, then the tenant's own toggles. A pack cannot enable a module the platform withheld.
4. The pack's own POS screen is rendered. It composes shared core components (cart, totals, payment, customer lookup) and its own field groups, filtered by the permissions the signed-in user actually holds.
5. Available product types are the pack's, plus generic ones, plus anything the owner added. **Owner additions always survive.**
6. Pricing and stock strategies are chosen **per line, by the item's product type** — never by the shop's vertical.

Step 6 is the subtle one. If the shop picked the strategy, a workshop could never sell a fixed-price bottle of oil over the counter and a petrol station could never run a mini-mart. Because the product type picks it, one bill can carry a scanned fixed-price item, a hand-priced repair and a lot-tracked medicine without the core caring what the shop calls itself.

## Catalogue Typing

A product type is not a category — categories group things on screen. A product type declares what kind of thing this is and therefore which rules apply. It is the join between a generic catalogue and a specific trade, and it is what makes creating a product easy: the owner picks *Medicine* and gets batch, expiry, salt and strength; they pick *Dish* and get modifiers and a prep station. They never see a field that does not apply.

### `product_types` (tenant)

Seeded by the shop's pack via S9, then editable by the owner.

| Column | Notes |
|---|---|
| `key` | `medicine`, `loose_good`, `dish`, `meal_plan`, `labour`, `oil` |
| `name` | What the owner sees |
| `pack_key` | Null means generic, offered to every shop |
| `stock_mode` | `none` / `simple` / `lot` — selects the StockStrategy (S6) |
| `pricing_mode` | `fixed` / `manual` / `recipe` / `plan` — selects the PriceResolver (S5). `plan` prices a subscription per cycle rather than a line per sale |
| `unit_mode` | `piece` / `measured` / `either` |
| `has_variants` | Pack sizes, strengths, colours |
| `has_modifiers` | "No onions", "extra shot" |
| `has_barcode` | |
| `is_sellable` | False for ingredients consumed by a recipe |
| `attribute_schema` | JSON: the extra fields this type asks for |
| `pos_render` | `tile` / `list` / `search` / `keypad` |
| `icon`, `colour`, `sort` | |

The three mode columns are the mechanism: **a row in a database selects a strategy object**. The core asks the product type how to price and how to draw stock, receives a strategy, and runs it. It never branches on the trade.

### Where trade-specific data lives

Two rules keep this from becoming a swamp:

- **Anything filtered, reported or priced on gets a real column in a real table** — `item_lots`, `item_barcodes`, `modifier_groups`, the existing `item_vehicle_compatibilities`.
- **Anything merely recorded and displayed goes in the JSON attribute bag** defined by `attribute_schema`.

The test is "will I ever need a `WHERE` on this?" Expiry, barcode, batch and price all fail that test on day one, so none of them belong in JSON however convenient it looks.

## Governance: The Rule of Two

The seams say where niche code may live. This rule says when it stops being niche. Without it the core starves while packs bloat into parallel applications.

> **Build it in the pack the first time. When a second pack needs the same thing, it moves to the core — before that second pack ships, not after.**

The migration is the price of the second use and is always cheaper than the third. The ratchet runs one way only: capabilities move from pack into core and never back out, so the core only accumulates things already proven in two real trades.

Applying the rule in advance:

- **Core:** barcodes (kirana, pharmacy), variants (kirana, pharmacy, restaurant), lot-tracked stock (pharmacy, perishables), customer credit ledger (every trade).
- **Core, by the settled decision to build two automotive packs:** vehicles, service history, inspections and vehicle-to-part compatibility. Two packs need all four, so the rule moves them out of the pack that has them today and into the core.
- **Core, because restaurant and mess both need them:** delivery (order type, address, rider assignment, post-kitchen status track) and the kitchen-screen transport — the mechanism that pushes outstanding work to a screen in the kitchen. What each pack renders on that screen differs and stays in the pack: the restaurant sees a queue of fired orders, the mess sees a production sheet of how many portions to cook.
- **Core, because mess and kirana both need them:** customer accounts and the credit ledger. Mess adds subscriptions on top; kirana uses the same ledger for udhaar.
- **Pack:** modifiers (restaurant only, for now); dispensed quantity and next-checkup mileage (oil change); job cards, labour operations and technician assignment (car maintenance); meal plans and attendance marking (mess).

The automotive split is the clearest illustration of the rule doing its job. Building the second automotive pack is what forces vehicles and service history into the core — and doing that migration *before* the second pack ships is the whole discipline. Deferred, it becomes two divergent copies of the same domain.

## Gaps in the Current Core

The seams are arrangement. These three are construction. Each is a property of the commerce core, each blocks more than one trade, and none is visible until a second kind of shop is attempted.

### 1. There is no selling price

`items.unit_cost` is what you paid. Every line is priced by hand through `sale_items.manually_charged_price`. Correct for a workshop where each job is negotiated; wrong for a shop where a barcode is scanned and the price is already decided.

Needs a **price book**: sell price, tax class, and room for tiers and history. This is what `pricing_mode: fixed` reads from, and it is the highest-leverage change in the plan.

*Blocks:* kirana, pharmacy, restaurant.

### 2. Stock is a single number

`items.stock_level` is one decimal per item, with no notion of which delivery a unit came from, what it cost on that delivery, or when it expires. A pharmacy cannot operate this way: batch and expiry are the job, and dispensing must draw from the batch expiring first.

Needs a **stock ledger with lots**, with `stock_level` demoted to a derived cache. This is what `stock_mode: lot` reads from, and true cost-of-goods reporting falls out of it for every trade.

*Blocks:* pharmacy. *Limits:* perishables, COGS reporting.

### 3. A sale is born complete

`App\Actions\RecordSale` goes from checkout to paid in one transaction. A kitchen needs the opposite: an order that opens, is added to over an hour, fires each course as it is ready, and only becomes a paid sale at the end — with line-level state, because that state is what a KDS renders.

Needs an **order lifecycle in front of the sale**. Statuses must not be bolted onto `sales`; the discipline that a sale is a settled financial record is worth keeping.

*Blocks:* restaurant and KDS. *Enables:* job cards, held bills.

### 4. Every sale is a one-off transaction

A sale is a single completed exchange. There is no concept of a customer who pays monthly for something consumed daily. A mess cannot operate this way: its revenue is a subscriber on a meal plan, billed per cycle, consuming meals that are marked rather than rung up, with a balance carried forward.

Needs **customer accounts with subscriptions**: a plan, a subscription against a customer, a billing cycle, and consumption events that draw against it rather than creating sales. This sits directly on top of the customer credit ledger that kirana needs anyway, which is why the ledger is worth building properly in Phase 1 rather than as a bolt-on.

*Blocks:* mess. *Related:* kirana credit (udhaar), which is the same ledger without the recurring part.

## Capability Placement

Read as the rule of two applied in advance. **Have** = in the core today; **Build** = new work, no blocker; **Gated** = waiting on a core gap; **—** = not needed.

| Capability | Lands in | Kirana | Oil change | Car maint. | Restaurant | Mess | Pharmacy |
|---|---|---|---|---|---|---|---|
| Catalogue & simple stock | Core | Have | Have | Have | Have | Have | Have |
| Expenses & cash drawer | Core | Have | Have | Have | Have | Have | Have |
| Suppliers & purchases | Core | Have | Have | Have | Have | Have | Have |
| Measured / weighed units | Core | Have | Have | Have | Have | Have | Have |
| Price book & tax class | Core | Gated | — | Build | Gated | Gated | Gated |
| Barcodes | Core | Build | — | — | — | — | Build |
| Variants | Core | Build | — | — | Build | — | Build |
| Customer accounts + ledger | Core | Build | Build | Build | Build | Build | Build |
| Order lifecycle | Core | — | — | Build | Gated | Gated | — |
| Delivery (type, address, rider) | Core | — | — | — | Build | Build | — |
| Kitchen-screen transport | Core | — | — | — | Build | Build | — |
| Subscriptions & billing cycles | Core | — | — | — | — | Gated | — |
| Lot-tracked stock + expiry | Core | Build | — | — | Build | Build | Gated |
| Vehicles, history, inspections | **Core** (moved in Phase 2) | — | Have | Have | — | — | — |
| Modifiers | Pack | — | — | — | Build | — | — |
| Tables & covers | Pack | — | — | — | Build | — | — |
| Order-queue kitchen view | Pack | — | — | — | Gated | — | — |
| Production-sheet kitchen view | Pack | — | — | — | — | Gated | — |
| Meal plans & attendance | Pack | — | — | — | — | Gated | — |
| Job cards & labour operations | Pack | — | — | Build | — | — | — |
| Prescriptions & register | Pack | — | — | — | — | — | Build |

## Decisions (settled 5 Sep 2026)

**Tenant schemas: per-vertical, applied when a pack is enabled.**
A shop's database carries only the tables its enabled packs need. Each pack owns its own migrations, and enabling a pack on a shop runs them into that tenant's database. This keeps databases lean while still allowing hybrids.

*Consequence — this is real work, budgeted in Phase 0.* `tenants:migrate` currently assumes one canonical set and `tenants:adopt-existing` validates against it; both need rework. "Pending migrations" becomes a per-shop question, so `CollectShopHealth` and the platform health badge must report against the shop's own expected set rather than a global one. Each tenant already has its own `migrations` table, so the storage side of this is straightforward; the tooling and health reporting are where the cost sits.

**Shop type: a soft preset.**
Stored on the shop, used to seed product types, packs and layout. It forbids nothing. A workshop can enable the retail pack later and start selling over the counter, at which point that pack's migrations run for it.

**POS: one screen per trade.**
Each pack owns its own POS screen rather than composing a shared shell.

*Consequence.* The maintenance cost is real and worth naming: a checkout bug is fixed once per trade. To keep that cost as low as the decision allows, everything genuinely shared — cart, line editing, totals, payment capture, customer lookup, the numeric keypad — is extracted into Blade components in the core that each screen composes. What a pack owns is the arrangement and its own capture blocks, not a private copy of the cart. Treat any logic that appears in two pack screens as a core component under the rule of two.

**KDS: short polling.**
The kitchen display polls a small open-orders endpoint every few seconds. No new infrastructure, works on the current shared host. Revisit only if a real kitchen reports lag.

**Automotive: two separate packs.**
Oil change and car maintenance are independent packs.

*Consequence — this changes capability placement.* Both packs need vehicles, service history, inspections and vehicle-to-part compatibility. Under the rule of two, capabilities needed by a second pack move into the core, so those four move from pack to **core** at the point the second automotive pack is built, and neither pack owns them. What stays in each pack is what actually differs: dispensed quantity and next-checkup mileage for oil change; job cards, labour operations and technician assignment for car maintenance.

**Tax: model the class now, and prices are tax-inclusive.**
A nullable tax class ships alongside the price book in Phase 1. The shelf price is what the customer pays, with tax **derived out of it** rather than added at the till.

*Consequence.* Tax is computed per line as `net = price / (1 + rate)`, rounded once at the line and then summed — deriving it on the summed total gives a different answer and is the classic source of one-rupee discrepancies. The customer-facing total never moves; net and tax are a decomposition on the invoice, not a recalculation. Both the rate and the derived amount are snapshotted per line so a later rate change cannot rewrite old invoices. Margin must be computed against the net price, or every margin figure is overstated by the tax rate. An exclusive-pricing mode may be added later as a per-shop setting; it is not built now.

No fiscal or regulatory integration is in scope. Whether Pakistan's retail invoicing rules apply to these customers remains open and is a question for an accountant.

**Mess billing: both arrears and advance, chosen per plan.**
Arrears is the default; advance is available where a shop wants it. Both share the same customer ledger — only the timing of the charge and the sign convention differ.

*Consequence.* A customer's balance now means opposite things depending on the plan: under arrears it is a debt, under advance it is a prepayment being drawn down. **No screen or statement may show a bare balance without its mode.** The billing mode is snapshotted on the subscription at signup, and the report that matters differs too — ageing under arrears, depletion under advance.

**Pharmacy: full scope.**
Batch and expiry, salt search and substitution, pack→box→strip→tablet units, prescriptions, controlled-substance register, sale returns, return-to-supplier, panel and insurance billing, cold-chain flagging, and refill tracking. Detailed in [the pharmacy pack page](2026-09-05-multi-vertical-pos/pack-pharmacy.md).

*Two carve-outs, both deliberate.* **Clinical decision support — drug interactions, contraindications, allergy and dose checking — is out of scope and must not be hand-rolled.** A wrong or incomplete interaction warning is more dangerous than none, because staff come to rely on it; it needs a licensed clinical database and a contract establishing responsibility. And the regulatory specifics throughout that page are *things to verify with a pharmacist and an accountant*, never things established here — they may add required fields and constrain what can be edited or deleted, which is far cheaper to design in than to retrofit.

## Phased Execution

Each phase pays for the next. Kirana comes first because it forces the price book and the customer ledger that four other trades wait on. Car maintenance comes second because it is mostly a rearrangement of what already exists. Restaurant comes third and deliberately builds delivery and the kitchen screen *in the core*, so the mess in Phase 4 inherits both. Pharmacy comes last: it carries the largest data change and is the one trade where a wrong model is a compliance problem rather than an inconvenience.

### Phase 0 — Cut the seam (medium, low risk)

Pure refactor with no behaviour change for the live shop. Introduce `product_types` and the nine-seam `VerticalPack` contract; extract the oil-change pack out of today's core; move the vehicle columns off `sales` into the vehicle field group; extract the shared POS components (cart, totals, payment, customer lookup) so pack screens compose rather than copy them; add the shop's `vertical` with a generic-retail fallback; land the architecture tests.

Also in this phase, because the per-vertical schema decision depends on it: **pack-scoped migrations**. Each pack owns its migration directory, enabling a pack runs its migrations into that tenant, `tenants:migrate` learns to compute the expected set per shop, `tenants:adopt-existing` validates against that per-shop set, and `CollectShopHealth` reports migration status against it. This is the largest single piece of Phase 0 and the one most likely to be underestimated.

**Done when:** Voltera Garage behaves identically; a shop created as "General store" opens a POS with no vehicle fields on it and no automotive tables in its database; and enabling the oil-change pack on that shop afterwards creates them.

### Phase 1 — Price book, then kirana (large, medium risk)

Sell price, tax class, barcodes, variants, and customer accounts with a credit ledger — all core, all forced into existence by one real shop. Build the ledger properly rather than as a kirana bolt-on: the mess subscriptions in Phase 4 sit directly on top of it, and retrofitting it then is the expensive path.

**Ships:** kirana and super-store POS. **Unblocks:** pricing for restaurant, mess and pharmacy, and the ledger the mess subscriptions need.

### Phase 2 — Car maintenance as a second automotive pack (medium, low risk)

Two pieces, in this order. First, **move vehicles, service history, inspections and vehicle-to-part compatibility from the oil-change pack into the core**, because a second pack now needs them — this is the rule of two being paid, and it must land before the new pack ships rather than after. Then build the car-maintenance pack itself: estimate → approval → work → invoice, labour operations with standard hours, technician assignment, and an open-jobs view.

**Ships:** car maintenance. **Proves:** the rule of two survives contact with a real second pack — which is the mechanism this whole design rests on, so a failure here is worth more than the feature.

### Phase 3 — Order lifecycle, delivery and the kitchen screen, then restaurant (x-large, high risk)

Land the order lifecycle in the core first and let car-maintenance job cards use it *before* the restaurant depends on it, so the foundation is proven by a trade already understood. Then the three core pieces the food trades share: **order type** (dine-in / takeaway / delivery) with address and rider assignment and a status track that continues after the kitchen is done; the **kitchen-screen transport** by polling; and the order-queue view the restaurant renders on it.

Then the restaurant pack itself: tables and covers, modifiers, prep stations, split bills.

**Ships:** restaurant and hotel POS with delivery and KDS. **Builds for Phase 4:** delivery and the kitchen screen are deliberately core here because the mess needs both.

### Phase 4 — Subscriptions, then mess and canteen (large, medium risk)

Customer subscriptions on top of the Phase 1 ledger: meal plans, billing cycles, advance payment and carried-forward balance, and consumption events that draw against a subscription instead of creating a sale. Then the mess pack: a rotating menu by day and meal, attendance marking, and a production-sheet view on the kitchen screen answering "how many portions tonight" from subscriber counts rather than from an order queue.

Walk-in single meals stay ordinary sales through the same counter.

**Ships:** mess / canteen / tiffin POS. **Reuses:** delivery and the kitchen screen built in Phase 3 — which is why mess follows restaurant rather than preceding it.

### Phase 5 — Stock lots, then pharmacy (x-large, high risk)

The stock ledger with lots, first-expiry-first dispensing, pack→strip→tablet units, salt search, prescription capture, near-expiry reporting. Migrate today's single-number stock into an opening lot per item so nothing is lost.

**Ships:** pharmacy POS. **Also delivers:** cost-of-goods reporting everywhere.

## Infrastructure Constraint

Production is shared cPanel hosting. Verified on 4 Sep 2026 during a deploy: no Node, no npm, and no practical way to run a long-lived process. Assets are built locally and uploaded.

This is adequate for everything running today and inadequate for a kitchen display, which is worth knowing in Phase 0 rather than Phase 3. A KDS wants a push connection and a push connection wants a daemon. In ascending order of cost: **short polling** works on the current host; **a hosted push service** buys real-time without running a daemon; **a VPS** unlocks queue workers, the scheduler, Reverb and a proper deploy pipeline — all four of which become desirable regardless of the KDS.

The same constraint touches background work. Receipt printing, near-expiry notifications and heavier reports are naturally queued jobs, and queued jobs on shared hosting mean cron-driven workers.

## Anti-Goals

- **Do not admit one conditional.** The first `if ($vertical === …)` in shared code is the whole failure mode, and it always arrives disguised as a five-minute shortcut. With one screen per trade the temptation moves into the shared components instead — a cart component that knows about prescriptions is the same mistake wearing a different hat.
- **Do not fork the repository per trade.** As soon as two forks both need a price book there are two price books and no platform.
- **Do not design all packs up front.** Every abstraction should be forced into existence by a second real shop, not by a fifth hypothetical one.
- **Do not let the two automotive packs each keep their own vehicles.** The split is a settled decision; the obligation it creates is that vehicles, service history and inspections move to the core in Phase 2. Two divergent copies of that domain is the failure mode to watch.
- **Do not put statuses on `sales`.** A sale stays an immutable financial record; mutability belongs to the order in front of it.
- **Do not build the mess before the restaurant.** Delivery and the kitchen screen are core pieces the restaurant phase pays for; taking the mess first means building both for one trade and then generalising them under pressure.
- **Do not model a mess subscription as a recurring sale.** A subscription is an agreement that generates charges against an account; meals consumed against it are attendance, not transactions. Collapsing the two makes every revenue report wrong and is very hard to unpick later.
- **Do not start with pharmacy.** It is the one trade where a wrong data model is a compliance problem rather than an inconvenience, and it should inherit a stock ledger two other trades have already exercised.
- **Do not skip Phase 0.** Shipping kirana by adding a conditional to the POS template will work exactly once.

---

*Grounded in the codebase at commit `8423930`. No code changed — design only.*
