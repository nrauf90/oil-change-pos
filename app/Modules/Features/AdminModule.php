<?php

namespace App\Modules\Features;

use App\Enums\Permission;
use App\Modules\Module;

class AdminModule extends Module
{
    public function key(): string
    {
        return 'admin';
    }

    public function title(): string
    {
        return 'Administration';
    }

    public function description(): string
    {
        return 'Staff accounts, roles and the module switchboard. Always available to the owner.';
    }

    public function icon(): string
    {
        return 'heroicon-o-key';
    }

    public function isCore(): bool
    {
        return true;
    }

    public function permissions(): array
    {
        return [
            Permission::ViewAnyUser,
            Permission::CreateUser,
            Permission::UpdateUser,
            Permission::DeleteUser,
            Permission::ManageModules,
            Permission::ManageRoles,
            Permission::ManageSuppliers,
            Permission::ViewActivityLog,
        ];
    }

    public function navigation(): array
    {
        return [
            [
                'route' => 'suppliers.index',
                'pattern' => 'suppliers*',
                'label' => 'Suppliers & Payables',
                'permission' => Permission::ManageSuppliers,
                'group' => 'account',
                'icon' => 'heroicon-o-truck',
            ],
            // An audit trail is looked at now and then, not worked in all day,
            // so it sits in the profile menu rather than the counter bar.
            [
                'route' => 'activity-log.index',
                'pattern' => 'activity-log*',
                'label' => 'Activity Log',
                'permission' => Permission::ViewActivityLog,
                'group' => 'account',
                'icon' => 'heroicon-o-clipboard-document-list',
            ],
        ];
    }
}
