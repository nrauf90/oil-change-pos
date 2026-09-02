<?php

namespace App\Filament\Platform\Resources\PlatformUsers\Pages;

use App\Filament\Platform\Resources\PlatformUsers\PlatformUserResource;
use App\Models\Central\PlatformUser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePlatformUser extends CreateRecord
{
    protected static string $resource = PlatformUserResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $isActive = (bool) ($data['is_active'] ?? true);
        unset($data['is_active'], $data['role'], $data['last_login_at']);

        $platformUser = new PlatformUser($data);
        $platformUser->forceFill([
            'role' => PlatformUser::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
        $platformUser->save();

        if (! $isActive) {
            $platformUser->deactivate();
        }

        return $platformUser;
    }
}
