<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The role lives in the spatie pivot rather than a users column, so the
     * form field is not dehydrated and is applied here instead.
     */
    protected function afterCreate(): void
    {
        $role = Role::tryFrom((string) ($this->data['role'] ?? ''));

        if ($role instanceof Role) {
            $this->record->assignRoleEnum($role);
        }
    }
}
