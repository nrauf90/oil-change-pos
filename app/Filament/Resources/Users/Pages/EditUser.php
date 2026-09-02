<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role as RoleModel;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    private ?string $roleName = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (): bool => $this->record->is(auth()->user()) || UserResource::isLastAdmin($this->record)),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['role'] = $this->record->roleName();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->roleName = (string) ($this->data['role'] ?? '');
        $willBeActive = (bool) ($this->data['is_active'] ?? $this->record->is_active);

        if (! RoleModel::query()->where('guard_name', 'web')->where('name', $this->roleName)->exists()) {
            $this->reject('role', 'Choose a valid role for this shop.');
        }

        if ($this->record->is(auth()->user())) {
            if (! $willBeActive) {
                $this->reject('is_active', 'You cannot deactivate your own signed-in account.');
            }

            if ($this->roleName !== $this->record->roleName()) {
                $this->reject('role', 'You cannot change the role of your own signed-in account.');
            }
        }

        if (UserResource::isLastAdmin($this->record)) {
            if (! $willBeActive) {
                $this->reject(
                    'is_active',
                    'This is the last active admin. Promote or activate another admin before deactivating this one.',
                );
            }

            if ($this->roleName !== Role::Admin->value) {
                $this->reject(
                    'role',
                    'This is the last active admin. Promote another staff member to admin before changing this role.',
                );
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->assignSingleRole((string) $this->roleName);
    }

    private function reject(string $field, string $message): never
    {
        $this->addError("data.{$field}", $message);
        $this->halt(shouldRollbackDatabaseTransaction: true);
    }
}
