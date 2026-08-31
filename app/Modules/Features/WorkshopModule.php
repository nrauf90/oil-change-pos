<?php

namespace App\Modules\Features;

use App\Enums\Permission;
use App\Modules\Module;

class WorkshopModule extends Module
{
    public function key(): string
    {
        return 'workshop';
    }

    public function title(): string
    {
        return 'Workshop Floor';
    }

    public function description(): string
    {
        return 'Vehicle service-history lookup by phone or plate, and multi-point inspection notes.';
    }

    public function icon(): string
    {
        return '&#128295;';
    }

    public function permissions(): array
    {
        return [
            Permission::LookupServiceHistory,
            Permission::ViewAnyInspection,
            Permission::CreateInspection,
            Permission::UpdateInspection,
        ];
    }

    public function navigation(): array
    {
        return [
            ['route' => 'service-history.index', 'pattern' => 'service-history*', 'label' => 'Vehicle History', 'permission' => Permission::LookupServiceHistory, 'icon' => '&#128663;'],
            ['route' => 'inspections.index', 'pattern' => 'inspections*', 'label' => 'Inspections', 'permission' => Permission::ViewAnyInspection, 'icon' => '&#128203;'],
        ];
    }
}
