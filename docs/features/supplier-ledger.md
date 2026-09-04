# Supplier ledger

## Purpose and workflow

Administration tracks who supplied materials, each delivery as a separate payable, an optional paper-bill image, and payments against that specific delivery. A supplier page summarizes deliveries and balances; a supply detail page shows its payment history and accepts cash, online, or card payments with an optional receipt screenshot.

Each supplier payment creates one linked `Shop Supplies` expense on the payment date. Recording payments rather than the full unpaid delivery prevents liabilities from appearing as money already spent and avoids double-counting installments. Cash payments affect the physical drawer; online and card payments remain in total expense reporting without reducing drawer cash.

Status: complete. Admin routes, screens, private evidence files, payment controls, audit entries, and feature coverage are implemented.

## Data and code map

- Suppliers: [`Supplier`](../../app/Models/Supplier.php), [`SupplierController`](../../app/Http/Controllers/SupplierController.php), [`SupplierRequest`](../../app/Http/Requests/SupplierRequest.php), [`suppliers migration`](../../database/migrations/tenant/2026_08_29_211509_create_suppliers_table.php)
- Supplies: [`Supply`](../../app/Models/Supply.php), [`SupplyController`](../../app/Http/Controllers/SupplyController.php), [`SupplyRequest`](../../app/Http/Requests/SupplyRequest.php), [`supplies migration`](../../database/migrations/tenant/2026_08_29_211510_create_supplies_table.php)
- Payments: [`SupplierPayment`](../../app/Models/SupplierPayment.php), [`SupplierPaymentController`](../../app/Http/Controllers/SupplierPaymentController.php), [`SupplierPaymentRequest`](../../app/Http/Requests/SupplierPaymentRequest.php), [`RecordSupplierPayment`](../../app/Actions/RecordSupplierPayment.php), [`supplier payments migration`](../../database/migrations/tenant/2026_08_29_211511_create_supplier_payments_table.php), [`PaymentMethod`](../../app/Enums/PaymentMethod.php)
- Expense integration: [`Expense`](../../app/Models/Expense.php), [`CashDrawer`](../../app/Support/CashDrawer.php), [`expense link migration`](../../database/migrations/tenant/2026_08_30_041907_link_supplier_payments_to_expenses.php)
- Access/navigation: [`Permission`](../../app/Enums/Permission.php), [`AdminModule`](../../app/Modules/Features/AdminModule.php), [`permission migration`](../../database/migrations/tenant/2026_08_29_211520_add_manage_suppliers_permission.php)
- Tests/factories: [`SupplierLedgerTest`](../../tests/Feature/SupplierLedgerTest.php), [`SupplierFactory`](../../database/factories/SupplierFactory.php), [`SupplyFactory`](../../database/factories/SupplyFactory.php), [`SupplierPaymentFactory`](../../database/factories/SupplierPaymentFactory.php)

## Permissions and invariants

- Every supplier, supply, payment, bill, and receipt endpoint requires authentication, the `admin` module, and `suppliers.manage`.
- A supply belongs to exactly one supplier; a payment belongs to exactly one supply. Nested route bindings must be checked so IDs from different parents return 404.
- `balance due = max(0, supply total - sum(payments))`. Validation must reject a payment above the current balance, including concurrent submissions; perform the final check and write transactionally.
- `expenses.supplier_payment_id` is unique: one supplier payment creates exactly one expense. Do not also create an expense for the full supply bill.
- Bill and receipt images accept only validated image files with bounded size. Store them on the private `local` disk and stream them through authorized actions; never expose raw storage paths or place them on a public disk.
- Payment actor comes from the authenticated session. A supplier with financial history cannot be deleted; user deletion only nulls the payment actor.
- Monetary amounts are positive decimals. Payment methods use [`PaymentMethod`](../../app/Enums/PaymentMethod.php); receipt/reference data is optional but should be retained as an immutable payment-history record.
