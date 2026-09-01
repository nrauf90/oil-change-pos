# Vehicle product compatibility

## Goal

Let the shop describe which vehicles a product fits and find matching products from both the POS and inventory screens. Universal products remain available regardless of the selected vehicle.

## Scope

- Products can be marked universal or assigned to one or more vehicle models.
- Vehicle makes contain reusable vehicle models.
- Each product-to-model assignment may have an optional inclusive start year and end year.
- Admins manage the shared make/model catalogue, while salespeople may add missing entries during POS product quick-add.
- POS and inventory product lists can be filtered by make, model, and year.
- Repair and service items do not use compatibility fields.

## Data model

### `vehicle_makes`

- `id`
- unique `name`
- timestamps

### `vehicle_models`

- `id`
- `vehicle_make_id`
- `name`
- timestamps
- unique within a make

### `item_vehicle_compatibilities`

- `id`
- `item_id`
- `vehicle_model_id`
- nullable `year_from`
- nullable `year_to`
- timestamps

The start and end years are inclusive. A missing start means no lower bound. A missing end means no upper bound. Duplicate compatibility ranges for the same product and model are rejected.

### `items`

Add `is_universal`, defaulting to `false`. Existing products are migrated to universal so the deployment does not hide the current catalogue. Repairs ignore this flag and cannot receive compatibility rows.

## Product management

The owner inventory form offers two compatibility modes for products:

- Universal product
- Vehicle-specific product

A vehicle-specific product requires at least one compatibility row. Each row selects a make and model and accepts optional From year and To year values. Model choices depend on the selected make. Year inputs are limited to the shop's reference range of 2000 through 2026.

Operators should mark a product as universal when it fits every vehicle they serve or when fitment still needs classification. Switching back to Universal clears any saved compatibility rows so the product stays visible for all vehicles.

For a vehicle-specific product, add one row per supported make/model/year band. Several rows may point to the same model when the year ranges differ, such as one row for `Toyota Corolla 2009-2013` and another for `Toyota Corolla 2014+`. Leave From year blank for older vehicles with no lower bound, or leave To year blank when the fitment stays open-ended.

Admins can create missing makes and models from the inventory controls, and only admins rename existing shared makes or models. Reuse an existing make whenever possible so filters and future products stay grouped under the same catalogue entry.

## Make and model management

The admin inventory area is the dedicated place to create and rename shared makes and models. Treat these records as shared catalogue data: a rename changes every product that points at that make or model, so only admins should perform it after confirming the new spelling or naming convention.

Salespeople do not rename shared records from POS. During product quick-add they may create a missing make or model needed to complete the current sale, then attach compatibility rows to the new product. Any later cleanup or rename stays with admins in inventory.

The inventory table shows a compact compatibility summary and supports Make, Model, and Year filters. Model choices depend on Make. Clearing Make clears Model. Clearing all three vehicle fields restores the complete inventory list.

Inventory filtering is intended for two jobs: checking whether a product is already classified for a customer's vehicle and finding uncategorized gaps. Select Make first, then Model, then Year when needed. Clearing those fields returns the full catalogue immediately.

## POS product picker

The picker adds Make, Model, and Year controls alongside the existing search and category controls. Filters apply only to product tiles. Services continue to follow the existing category and text filters.

When any vehicle filter is active, matching results contain:

- universal products; and
- vehicle-specific products matching all supplied fields.

If a year is supplied, it must fall inside the compatibility row's inclusive bounds. A row with a missing bound remains open in that direction. "All vehicles" clears Make, Model, and Year.

Counter staff should use these filters before recommending vehicle-specific products. Start with Make and Model, add Year only when the part changed across generations, and use "All vehicles" to return to the unfiltered product list.

## POS quick-add

The quick-add modal shows compatibility controls only for products. The salesperson can mark the product universal or add one or more make/model/year rows. Missing makes and models can be created inside the modal without leaving the sale, but existing shared records are renamed only from the admin inventory workflow.

The request saves the item and any newly created vehicle records in one database transaction. Validation failures return field-level errors and preserve the current cart and quick-add values.

Quick-add follows the same operator workflow as inventory: mark the product universal when it fits broadly or when the shop has not classified it yet, otherwise add each supported make/model/year row before saving. When a missing make or model is discovered at the counter, create it in the modal, stay within the 2000-2026 year inputs, and then finish the compatibility rows without leaving the sale screen.

## Validation

- Make and model names are required when created, trimmed, and unique without case-sensitive duplicates.
- A model must belong to the selected make.
- Years are four-digit integers between 2000 and 2026.
- `year_from` cannot exceed `year_to`.
- Vehicle-specific products require at least one complete compatibility row.
- Universal products cannot retain compatibility rows.
- Repairs cannot be universal or vehicle-specific.

## Testing

Implementation follows red-green-refactor. Feature and model tests cover:

- relationships, uniqueness, and year-bound matching;
- universal and vehicle-specific validation;
- syncing compatibility from inventory create/edit;
- make/model/year filtering on inventory and POS;
- universal products remaining visible under vehicle filters;
- quick-add creation with existing and newly created makes/models;
- transaction rollback and preservation of validation errors;
- repair behavior remaining unchanged;
- authorization and unit-cost confidentiality remaining intact.

After implementation, run the affected PHPUnit tests, Laravel Pint for changed PHP files, the full test suite, and the frontend build. A separate read-only review agent reviews the completed diff before final verification.
