# Sales and invoices

## Purpose and workflow

Completed sales are browsed and searched, opened as immutable invoice detail, and exported to PDF. Authorized users may delete a sale; audit logging retains a snapshot of the destructive action.

## Data and code map

- Controller/routes: [`SaleController`](../../app/Http/Controllers/SaleController.php); `sales.index`, `sales.show`, `sales.pdf`, `sales.destroy` in [`routes/web.php`](../../routes/web.php)
- Models/tables: [`Sale`](../../app/Models/Sale.php) / [`sales migration`](../../database/migrations/2026_01_01_000200_create_sales_table.php); [`SaleItem`](../../app/Models/SaleItem.php) / [`sale_items migration`](../../database/migrations/2026_01_01_000300_create_sale_items_table.php)
- Views: [`sales/index.blade.php`](../../resources/views/sales/index.blade.php), [`sales/show.blade.php`](../../resources/views/sales/show.blade.php), [`sales/pdf.blade.php`](../../resources/views/sales/pdf.blade.php)
- Creation: [`RecordSale`](../../app/Actions/RecordSale.php), [`SaleTotalCalculator`](../../app/Support/SaleTotalCalculator.php)
- Audit: [`SaleObserver`](../../app/Observers/SaleObserver.php)
- Tests: [`SaleRecordTest`](../../tests/Feature/SaleRecordTest.php), [`InvoiceAndHistoryTest`](../../tests/Feature/InvoiceAndHistoryTest.php), [`DestructiveActionTest`](../../tests/Feature/DestructiveActionTest.php), [`UnitCostConfidentialityTest`](../../tests/Feature/UnitCostConfidentialityTest.php)

## Permissions and invariants

- Permissions are `sales.view_any`, `sales.view`, `sales.export_pdf`, and `sales.delete`; pricing visibility additionally uses `pricing.view`.
- `invoice_number` is unique. `item_name`, line type, quantity/dispensed quantity, and manually charged price are historical snapshots.
- Deleting an inventory item nulls `sale_items.item_id`; it must not alter prior invoices. Deleting a sale cascades its lines and must create an activity-log record.
- Never recompute a stored invoice using current product price, name, or cost.

