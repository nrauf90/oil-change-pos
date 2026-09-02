<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    private ?string $roleName = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->roleName = (string) ($this->data['role'] ?? '');

        if (! Role::query()->where('guard_name', 'web')->where('name', $this->roleName)->exists()) {
            $this->addError('data.role', 'Choose a valid role for this shop.');
            $this->halt(shouldRollbackDatabaseTransaction: true);
        }

        return $data;
    }

    /**
     * The role lives in the spatie pivot rather than a users column, so the
     * form field is not dehydrated and is applied here instead.
     */
    protected function afterCreate(): void
    {
        $this->record->assignSingleRole((string) $this->roleName);
    }
}
