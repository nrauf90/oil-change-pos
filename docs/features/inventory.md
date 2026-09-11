# Inventory and stock

## Purpose and workflow

Authorized staff manage products and service/task items, activation, unit cost, selling price, category, photo, and stock. Piece-counted and measured inventory are supported; checkout decrements stock while preserving the exact dispensed quantity used for consumption and margin reporting. POS quick-add avoids losing an in-progress bill. The inventory list supports live search/filtering (name, type, status, category) without a full page reload. A vehicle-specific product can carry make/model/year compatibility rows directly from the staff form, reusing the same `ItemVehicleCompatibility` data Filament already edits.

## Data and code map

- CRUD: [`ItemController`](../../app/Http/Controllers/ItemController.php), [`ItemRequest`](../../app/Http/Requests/ItemRequest.php), [`items views`](../../resources/views/items) (`index`/`_results` split for live search, `_form` for the shared create/edit fields)
- POS endpoints: [`QuickItemController`](../../app/Http/Controllers/QuickItemController.php), [`QuickItemRequest`](../../app/Http/Requests/QuickItemRequest.php)
- Model/schema: [`Item`](../../app/Models/Item.php), [`base migration`](../../database/migrations/tenant/2026_01_01_000100_create_items_table.php), [`stock columns`](../../database/migrations/tenant/2026_08_27_000102_add_stock_columns_to_items_table.php), [`measured stock`](../../database/migrations/tenant/2026_08_29_000101_add_measured_stock_to_items_table.php), [`selling price/category/image columns`](../../database/migrations/tenant/2026_09_10_120100_add_selling_price_category_and_image_to_items_table.php)
- Categories: [`Category`](../../app/Models/Category.php), [`categories migration`](../../database/migrations/tenant/2026_09_10_120000_create_categories_table.php), admin CRUD via [`CategoryResource`](../../app/Filament/Resources/Categories/CategoryResource.php)
- Item photo: [`AttachItemImage`](../../app/Actions/AttachItemImage.php) (private `local` disk, tenant-prefixed, streamed back via the `items.image` route — never a public URL, mirrors `AttachExpenseReceipts`)
- Types/units: [`ItemType`](../../app/Enums/ItemType.php), [`UnitOfMeasure`](../../app/Enums/UnitOfMeasure.php)
- Filament alternative UI: [`ItemResource`](../../app/Filament/Resources/Items/ItemResource.php)
- Audit: [`ItemObserver`](../../app/Observers/ItemObserver.php)
- Tests: [`ItemManagementTest`](../../tests/Feature/ItemManagementTest.php), [`ItemInventoryEnhancementsTest`](../../tests/Feature/ItemInventoryEnhancementsTest.php), [`CategoryManagementTest`](../../tests/Feature/CategoryManagementTest.php), [`StockManagementTest`](../../tests/Feature/StockManagementTest.php), [`MeasuredStockTest`](../../tests/Feature/MeasuredStockTest.php), [`MeasuredInventoryUiTest`](../../tests/Feature/MeasuredInventoryUiTest.php), [`QuickAddItemTest`](../../tests/Feature/QuickAddItemTest.php)

## Permissions and invariants

- View/create/quick-create/update/delete, unit-cost view/set, and stock view/manage are separate `items.*` permissions. Selling price and category follow the same visibility as the rest of the item form/list — no new permission.
- Item names are unique. Inactive items remain available to historical sales but should not be offered for new sales.
- Unit cost is confidential and must be excluded server-side when permission is absent; it is dropped from the inventory list entirely (selling price replaces it there) but stays on the form, gated as before.
- Stock deductions are transactional with checkout. Measured stock uses its declared unit and the stored dispensed quantity; never silently treat it as pieces.
- `selling_price` is inventory reference/list data only — it is not wired into POS checkout, quick-add, or margin reporting.
- Category admin CRUD is admin-only and module-gated to `inventory`, mirroring `VehicleMakeResource`'s gating. Deleting a category `nullOnDelete()`s `items.category_id` rather than blocking the delete.

