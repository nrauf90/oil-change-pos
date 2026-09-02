<?php

namespace App\Modules\Features;

use App\Enums\Permission;
use App\Modules\Module;

class InventoryModule extends Module
{
    public function key(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'Inventory & Stock';
    }

    public function description(): string
    {
        return 'Products and repair tasks, unit costs, stock levels and low-stock alerts.';
    }

    public function icon(): string
    {
        return 'heroicon-o-cube';
    }

    public function isCore(): bool
    {
        return true;
    }

    public function permissions(): array
    {
        return [
            Permission::ViewAnyItem,
            Permission::CreateItem,
            Permission::QuickCreateItem,
            Permission::UpdateItem,
            Permission::DeleteItem,
            Permission::ViewItemUnitCost,
            Permission::SetItemUnitCost,
            Permission::ViewStock,
            Permission::ManageStock,
        ];
    }

    public function navigation(): array
    {
        return [
            ['route' => 'items.index', 'pattern' => 'items*', 'label' => 'Inventory', 'permission' => Permission::ViewAnyItem, 'icon' => 'heroicon-o-cube'],
        ];
    }
}
