<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->staff();
        $this->call(TenantReferenceDataSeeder::class);
    }

    /**
     * One account per role for local, single-shop installations.
     * SaaS provisioning never invokes this seeder.
     */
    private function staff(): void
    {
        $accounts = [
            [Role::Admin, 'owner', 'Shop Owner'],
            [Role::Manager, 'counter', 'Front Desk'],
            [Role::Technician, 'mechanic', 'Workshop Technician'],
        ];

        foreach ($accounts as [$role, $username, $name]) {
            $user = User::firstOrCreate(
                ['username' => $username],
                ['name' => $name, 'password' => Hash::make('password'), 'is_active' => true],
            );

            $user->assignRoleEnum($role);
        }
    }
}
