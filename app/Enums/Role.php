<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Technician = 'technician';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin / Owner',
            self::Manager => 'Manager / Front-Desk Cashier',
            self::Technician => 'Technician / Mechanic',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Full system access: financials, margins, deletions, users and modules.',
            self::Manager => 'Runs the counter: sales, on-the-fly items, expenses and the daily cash drawer.',
            self::Technician => 'Workshop floor: scripts, vehicle history lookup and inspection notes. No pricing, no cash.',
        };
    }

    /**
     * The permission bundle for this role — the single source of truth that the
     * roles/permissions tables are seeded from.
     *
     * @return array<int, Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // The owner can do everything, including anything added later.
            self::Admin => Permission::cases(),

            self::Manager => [
                Permission::UsePos,
                Permission::ViewAnySale,
                Permission::ViewSale,
                Permission::CreateSale,
                Permission::ExportSalePdf,
                Permission::ViewAnyDraftSale,
                Permission::CreateDraftSale,
                Permission::CompleteDraftSale,
                Permission::ViewPricing,
                Permission::ViewAnyItem,
                Permission::CreateItem,
                Permission::QuickCreateItem,
                Permission::UpdateItem,
                Permission::ViewStock,
                Permission::ManageStock,
                Permission::ViewDashboard,
                Permission::ViewAnyExpense,
                Permission::CreateExpense,
                Permission::UpdateExpense,
                Permission::ViewCashDrawer,
                Permission::ViewScripts,
                Permission::LookupServiceHistory,
                Permission::ViewAnyInspection,
                Permission::CreateInspection,
                Permission::UpdateInspection,
                // Deliberately withheld: DeleteSale, DeleteItem, DeleteExpense,
                // ViewItemUnitCost, SetItemUnitCost, ViewMargins, users.*, logs.view.
            ],

            self::Technician => [
                Permission::ViewScripts,
                Permission::LookupServiceHistory,
                Permission::ViewAnyInspection,
                Permission::CreateInspection,
                Permission::UpdateInspection,
                // Deliberately withheld: anything that reveals a price, a cost,
                // a total or the cash drawer, and anything that creates a bill.
            ],
        };
    }

    /** @return array<int, string> */
    public function permissionNames(): array
    {
        return array_map(fn (Permission $permission) => $permission->value, $this->permissions());
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
