<?php

namespace App\Modules\Features;

use App\Enums\Permission;
use App\Modules\Module;

class SalesModule extends Module
{
    public function key(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'Point of Sale & Billing';
    }

    public function description(): string
    {
        return 'The counter screen, flexible manual pricing, invoices and sales history.';
    }

    public function icon(): string
    {
        return '&#128179;';
    }

    public function isCore(): bool
    {
        return true;
    }

    public function dependsOn(): array
    {
        return ['inventory'];
    }

    public function permissions(): array
    {
        return [
            Permission::UsePos,
            Permission::ViewAnySale,
            Permission::ViewSale,
            Permission::CreateSale,
            Permission::DeleteSale,
            Permission::ExportSalePdf,
            Permission::ViewPricing,
        ];
    }

    public function navigation(): array
    {
        return [
            ['route' => 'pos.create', 'pattern' => 'pos*', 'label' => 'New Sale', 'permission' => Permission::UsePos, 'icon' => '&#128179;'],
            ['route' => 'sales.index', 'pattern' => 'sales*', 'label' => 'Sales', 'permission' => Permission::ViewAnySale, 'icon' => '&#129534;'],
        ];
    }
}
