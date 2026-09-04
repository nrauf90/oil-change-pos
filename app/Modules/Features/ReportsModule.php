<?php

namespace App\Modules\Features;

use App\Enums\Permission;
use App\Modules\Module;

class ReportsModule extends Module
{
    public function key(): string
    {
        return 'reports';
    }

    public function title(): string
    {
        return 'Reporting Dashboard';
    }

    public function description(): string
    {
        return 'Daily, weekly and monthly revenue from actually-charged values, plus margin analysis.';
    }

    public function icon(): string
    {
        return 'heroicon-o-chart-bar-square';
    }

    public function dependsOn(): array
    {
        return ['sales'];
    }

    public function permissions(): array
    {
        return [
            Permission::ViewDashboard,
            Permission::ViewMargins,
        ];
    }

    public function navigation(): array
    {
        return [
            // Owner-facing reading, not counter work: lives in the profile menu.
            ['route' => 'reports.index', 'pattern' => 'reports*', 'label' => 'Dashboard', 'permission' => Permission::ViewDashboard, 'group' => 'account', 'icon' => 'heroicon-o-chart-bar-square'],
        ];
    }
}
