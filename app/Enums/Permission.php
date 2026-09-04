<?php

namespace App\Enums;

/**
 * Every guarded action in the application, one case each.
 *
 * Nothing in the app should check a role directly — check a permission. Roles
 * are only a convenient bundle of these, defined in {@see Role::permissions()}.
 */
enum Permission: string
{
    /* ---- Point of sale --------------------------------------------- */
    case UsePos = 'pos.use';

    /* ---- Sales ----------------------------------------------------- */
    case ViewAnySale = 'sales.view_any';
    case ViewSale = 'sales.view';
    case CreateSale = 'sales.create';
    case DeleteSale = 'sales.delete';
    case ExportSalePdf = 'sales.export_pdf';

    /* ---- Draft sales ----------------------------------------------- */
    case ViewAnyDraftSale = 'draft_sales.view_any';
    case CreateDraftSale = 'draft_sales.create';
    case CompleteDraftSale = 'draft_sales.complete';
    case DeleteDraftSale = 'draft_sales.delete';

    /* ---- Pricing --------------------------------------------------- */
    case ViewPricing = 'pricing.view';

    /* ---- Inventory ------------------------------------------------- */
    case ViewAnyItem = 'items.view_any';
    case CreateItem = 'items.create';
    case QuickCreateItem = 'items.quick_create';
    case UpdateItem = 'items.update';
    case DeleteItem = 'items.delete';
    case ViewItemUnitCost = 'items.view_unit_cost';
    case SetItemUnitCost = 'items.set_unit_cost';
    case ViewStock = 'items.view_stock';
    case ManageStock = 'items.manage_stock';

    /* ---- Reporting ------------------------------------------------- */
    case ViewDashboard = 'reports.view_dashboard';
    case ViewMargins = 'reports.view_margins';

    /* ---- Expenses & cash flow -------------------------------------- */
    case ViewAnyExpense = 'expenses.view_any';
    case CreateExpense = 'expenses.create';
    case UpdateExpense = 'expenses.update';
    case DeleteExpense = 'expenses.delete';
    case ViewCashDrawer = 'expenses.view_cash_drawer';

    /* ---- Users ----------------------------------------------------- */
    case ViewAnyUser = 'users.view_any';
    case CreateUser = 'users.create';
    case UpdateUser = 'users.update';
    case DeleteUser = 'users.delete';

    /* ---- Workshop floor -------------------------------------------- */
    case ViewScripts = 'scripts.view';
    case LookupServiceHistory = 'service_history.lookup';
    case ViewAnyInspection = 'inspections.view_any';
    case CreateInspection = 'inspections.create';
    case UpdateInspection = 'inspections.update';

    /* ---- Administration -------------------------------------------- */
    case ManageModules = 'modules.manage';

    // Deliberately separate from ManageModules: handing somebody the module
    // switchboard must not also hand them the power to rewrite the role matrix.
    case ManageRoles = 'roles.manage';
    case ManageSuppliers = 'suppliers.manage';

    /* ---- Audit ----------------------------------------------------- */
    case ViewActivityLog = 'logs.view';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return ucfirst(str_replace(['.', '_'], [': ', ' '], $this->value));
    }

    /** The area this permission belongs to, for grouping in the admin UI. */
    public function group(): string
    {
        return match (true) {
            str_starts_with($this->value, 'pos.') => 'Point of sale',
            str_starts_with($this->value, 'sales.'),
            str_starts_with($this->value, 'draft_sales.') => 'Sales',
            str_starts_with($this->value, 'pricing.') => 'Pricing',
            str_starts_with($this->value, 'items.') => 'Inventory',
            str_starts_with($this->value, 'reports.') => 'Reporting',
            str_starts_with($this->value, 'expenses.') => 'Expenses & cash flow',
            str_starts_with($this->value, 'users.') => 'Users',
            str_starts_with($this->value, 'modules.'),
            str_starts_with($this->value, 'roles.'),
            str_starts_with($this->value, 'suppliers.') => 'Administration',
            str_starts_with($this->value, 'logs.') => 'Audit',
            default => 'Workshop floor',
        };
    }
}
