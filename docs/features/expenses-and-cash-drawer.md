# Expenses and cash drawer

## Purpose and workflow

Staff log shop outlays with category, amount, description, and the time cash left. The cash drawer reconciles cash sales against expenses for a selected window and shows inflow, outflow, net position, counts, and breakdowns.

Supplier payments automatically appear here as `Shop Supplies`. All supplier payment methods count toward total expenses, but only cash payments reduce the physical cash drawer.

## Data and code map

- Routes: [`routes/modules/expenses.php`](../../routes/modules/expenses.php)
- Expenses: [`ExpenseController`](../../app/Http/Controllers/ExpenseController.php), [`ExpenseRequest`](../../app/Http/Requests/ExpenseRequest.php), [`Expense`](../../app/Models/Expense.php), [`expenses migration`](../../database/migrations/tenant/2026_08_27_000210_create_expenses_table.php), [`expense views`](../../resources/views/expenses)
- Drawer: [`CashDrawerController`](../../app/Http/Controllers/CashDrawerController.php), [`CashDrawer`](../../app/Support/CashDrawer.php), [`cash-drawer/index.blade.php`](../../resources/views/cash-drawer/index.blade.php)
- Category/audit: [`ExpenseCategory`](../../app/Enums/ExpenseCategory.php), [`ExpenseObserver`](../../app/Observers/ExpenseObserver.php)
- Tests: [`ExpenseTest`](../../tests/Feature/ExpenseTest.php), [`CashDrawerTest`](../../tests/Feature/CashDrawerTest.php), [`DestructiveActionTest`](../../tests/Feature/DestructiveActionTest.php)

## Permissions and invariants

- Expense view/create/update/delete and `expenses.view_cash_drawer` are separate permissions. Managers may correct records; deletion is intentionally more restricted.
- `spent_at`, not record creation time, determines the accounting window. Expense creator comes from the authenticated user and becomes null if that user is deleted.
- Amounts are positive decimals. Destructive changes are audit logged.
- Drawer totals must include only the payment methods treated as physical cash by [`PaymentMethod`](../../app/Enums/PaymentMethod.php).
