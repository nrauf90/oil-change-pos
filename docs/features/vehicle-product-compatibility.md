# Vehicle product compatibility

## Goal

Let the shop describe which vehicles a product fits and find matching products from both the POS and inventory screens. Universal products remain available regardless of the selected vehicle.

## Scope

- Products can be marked universal or assigned to one or more vehicle models.
- Vehicle makes contain reusable vehicle models.
- Each product-to-model assignment may have an optional inclusive start year and end year.
- A missing make or model can be created while a salesperson quick-adds a product from the POS.
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

A vehicle-specific product requires at least one compatibility row. Each row selects a make and model and accepts optional From year and To year values. Model choices depend on the selected make. Users may add missing makes and models from the controls.

The inventory table shows a compact compatibility summary and supports Make, Model, and Year filters. Model choices depend on Make. Clearing Make clears Model. Clearing all three vehicle fields restores the complete inventory list.

## POS product picker

The picker adds Make, Model, and Year controls alongside the existing search and category controls. Filters apply only to product tiles. Services continue to follow the existing category and text filters.

When any vehicle filter is active, matching results contain:

- universal products; and
- vehicle-specific products matching all supplied fields.

If a year is supplied, it must fall inside the compatibility row's inclusive bounds. A row with a missing bound remains open in that direction. "All vehicles" clears Make, Model, and Year.

## POS quick-add

The quick-add modal shows compatibility controls only for products. The salesperson can mark the product universal or add one or more make/model/year rows. Missing makes and models can be created inside the modal without leaving the sale.

The request saves the item and any newly created vehicle records in one database transaction. Validation failures return field-level errors and preserve the current cart and quick-add values.

## Validation

- Make and model names are required when created, trimmed, and unique without case-sensitive duplicates.
- A model must belong to the selected make.
- Years are four-digit integers in a reasonable supported range.
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

