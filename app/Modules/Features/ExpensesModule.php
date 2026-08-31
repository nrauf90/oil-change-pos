<?php

namespace App\Modules\Features;

use App\Enums\Permission;
use App\Modules\Module;

class ExpensesModule extends Module
{
    public function key(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return 'Daily Expenses & Cash Flow';
    }

    public function description(): string
    {
        return 'Log shop outlays and reconcile the physical cash drawer at end of shift.';
    }

    public function icon(): string
    {
        return '&#128181;';
    }

    public function dependsOn(): array
    {
        return ['sales'];
    }

    public function permissions(): array
    {
        return [
            Permission::ViewAnyExpense,
            Permission::CreateExpense,
            Permission::UpdateExpense,
            Permission::DeleteExpense,
            Permission::ViewCashDrawer,
        ];
    }

    public function navigation(): array
    {
        return [
            ['route' => 'expenses.index', 'pattern' => 'expenses*', 'label' => 'Expenses', 'permission' => Permission::ViewAnyExpense, 'icon' => '&#128181;'],
            ['route' => 'cash-drawer.index', 'pattern' => 'cash-drawer*', 'label' => 'Cash Drawer', 'permission' => Permission::ViewCashDrawer, 'icon' => '&#128176;'],
        ];
    }
}
