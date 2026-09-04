# Product types

**Phase:** 0 · **Lands in:** Core · **Status:** Planned
**Depends on:** [vertical-packs](vertical-packs.md)
**Blocks:** [price-book](price-book.md), [stock-lots](stock-lots.md), every pack

## Why

This is the join between a generic catalogue and a specific trade. It is what makes "create a product" easy for a shop owner: they pick *Medicine* and get batch, expiry, salt and strength; they pick *Dish* and get modifiers and a prep station. They never see a field that does not apply to them.

A product type is **not** a category. Categories group things on screen. A product type declares what kind of thing this is and therefore which rules apply to it.

## The mechanism

Three mode columns let **a row in a database select a strategy object**. The core asks the product type how to price and how to draw stock, receives a strategy, and runs it. It never branches on the trade. This is the single most important idea in the design.

## Data model

`product_types` (tenant), seeded by a pack via S9, then editable by the owner.

| Column | Notes |
|---|---|
| `key` | `medicine`, `loose_good`, `dish`, `meal_plan`, `labour`, `oil` |
| `name` | What the owner sees |
| `pack_key` | Null means generic, offered to every shop |
| `stock_mode` | `none` / `simple` / `lot` — selects the StockStrategy |
| `pricing_mode` | `fixed` / `manual` / `recipe` / `plan` — selects the PriceResolver |
| `unit_mode` | `piece` / `measured` / `either` |
| `has_variants` | Pack sizes, strengths, colours |
| `has_modifiers` | "No onions", "extra shot" |
| `has_barcode` | |
| `is_sellable` | False for ingredients consumed by a recipe |
| `attribute_schema` | JSON: the extra fields this type asks for |
| `pos_render` | `tile` / `list` / `search` / `keypad` |
| `icon`, `colour`, `sort` | |

`items` gains `product_type_id`.

## Where trade-specific data lives

- **Anything filtered, reported or priced on gets a real column in a real table** — `item_lots`, `item_barcodes`, `modifier_groups`, the existing `item_vehicle_compatibilities`.
- **Anything merely recorded and displayed goes in the JSON attribute bag** defined by `attribute_schema`.

The whole test is *"will I ever need a `WHERE` on this?"* Expiry, barcode, batch and price all fail that test on day one and must not live in JSON however convenient it looks.

## Invariants

- Strategies are chosen **per line, by the item's product type** — never by the shop's vertical. This is what lets one bill carry a scanned fixed-price item, a hand-priced repair and a lot-tracked medicine.
- Product types the owner added always survive a pack being re-seeded or re-enabled.

## Migration of existing data

Today's `ItemType` is only `Product` and `Repair`. Existing items map to seeded types in the oil-change pack: `Product` becomes an `oil` or `part` type by unit of measure, `Repair` becomes a `service` type with `stock_mode: none` and `pricing_mode: manual`.

## Open questions

- Can an owner create a product type from scratch, or only edit seeded ones? Creating one implies exposing `attribute_schema` editing, which is a much larger UI.
