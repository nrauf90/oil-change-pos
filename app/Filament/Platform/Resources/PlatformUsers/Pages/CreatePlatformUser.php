<?php

namespace App\Filament\Platform\Resources\PlatformUsers\Pages;

use App\Actions\ManagePlatformUsers;
use App\Filament\Platform\Resources\PlatformUsers\PlatformUserResource;
use App\Models\Central\PlatformUser;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreatePlatformUser extends CreateRecord
{
    protected static string $resource = PlatformUserResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Auth::guard('platform')->user();

        if (! $actor instanceof PlatformUser) {
            throw new AuthorizationException('Platform administrator authentication is required.');
        }

        return resolve(ManagePlatformUsers::class)->create($actor, $data);
    }
}
