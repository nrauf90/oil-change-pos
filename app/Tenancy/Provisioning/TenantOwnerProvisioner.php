<?php

namespace App\Tenancy\Provisioning;

use App\Exceptions\TenantProvisioningException;
use App\Models\Central\ShopOwner;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

final readonly class TenantOwnerProvisioner
{
    public function ensureOwner(
        ShopOwner $centralOwner,
        #[\SensitiveParameter]
        ?string $temporaryPassword,
    ): User {
        $owner = User::query()->where('username', $centralOwner->username)->first();

        if ($owner instanceof User) {
            return $owner;
        }

        if (! is_string($temporaryPassword) || $temporaryPassword === '') {
            throw TenantProvisioningException::safe(
                'owner',
                'OWNER_PASSWORD_REQUIRED',
                'A fresh temporary owner password is required to resume provisioning.',
            );
        }

        return User::query()->create([
            'name' => $centralOwner->name,
            'username' => $centralOwner->username,
            'password' => Hash::make($temporaryPassword),
            'is_active' => true,
        ]);
    }
}
