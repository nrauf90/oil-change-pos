<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (): bool => $this->record->is(auth()->user())),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = $this->record->role()?->value;

        return $data;
    }

    protected function afterSave(): void
    {
        $role = Role::tryFrom((string) ($this->data['role'] ?? ''));

        if ($role instanceof Role) {
            $this->record->assignRoleEnum($role);
        }
    }
}
