# Point of sale

## Purpose and workflow

The counter builds a sale from inventory or custom lines, types actual charged prices and quantities, adds customer/vehicle details, then checks out. A single live-search field requests saved customer/vehicle matches after three characters and displays matches in a scrollable result panel. Checkout saves or refreshes the reusable vehicle profile.

## Data and code map

- Screen/search: [`PosController`](../../app/Http/Controllers/PosController.php), [`pos/create.blade.php`](../../resources/views/pos/create.blade.php)
- Checkout: [`SaleController`](../../app/Http/Controllers/SaleController.php), [`StoreSaleRequest`](../../app/Http/Requests/StoreSaleRequest.php), [`RecordSale`](../../app/Actions/RecordSale.php), [`SaleTotalCalculator`](../../app/Support/SaleTotalCalculator.php)
- Quick-add inventory: [`QuickItemController`](../../app/Http/Controllers/QuickItemController.php), [`QuickItemRequest`](../../app/Http/Requests/QuickItemRequest.php)
- Models/tables: [`CustomerVehicle`](../../app/Models/CustomerVehicle.php) / [`customer_vehicles`](../../database/migrations/2026_08_29_204229_create_customer_vehicles_table.php); [`Sale`](../../app/Models/Sale.php), [`SaleItem`](../../app/Models/SaleItem.php)
- Routes: `pos.create`, `customer-vehicles.index`, `sales.store`, `quick-items.*` in [`routes/web.php`](../../routes/web.php)
- Tests: [`CheckoutTest`](../../tests/Feature/CheckoutTest.php), [`CustomerVehicleTest`](../../tests/Feature/CustomerVehicleTest.php), [`QuickAddItemTest`](../../tests/Feature/QuickAddItemTest.php), [`PosCategoryRailTest`](../../tests/Feature/PosCategoryRailTest.php), [`PosDispenseUiTest`](../../tests/Feature/PosDispenseUiTest.php), [`MoneyParityTest`](../../tests/Feature/MoneyParityTest.php)

## Permissions and invariants

- `pos.use` opens the screen/search; `sales.create` checks out; quick item endpoints separately require item permissions. JSON write/search endpoints are throttled.
- Search must begin at three characters, escape SQL wildcard characters, and return all matches for the scrollable panel. The initial page should not preload the full customer table.
- `vehicle_plate` uniquely identifies a saved vehicle when present. Blank submitted values must not erase useful saved details.
- Sale totals come only from submitted charged-price snapshots, not item unit cost/reference data. Unit cost must never leak to an unauthorized salesperson.
- Checkout, stock deduction, sale lines, and customer-vehicle update belong in one database transaction.

