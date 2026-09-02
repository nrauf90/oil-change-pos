<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\ManageTenantUsers;
use App\Enums\Role;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role as RoleModel;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use LogicException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ?bool $hasDatabaseTransactions = false;

    private ?string $roleName = null;

    protected function getHeaderActions(): array
    {
        return [
            UsersTable::deleteAction(),
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

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = Auth::user();

        if (! $actor instanceof User || ! $record instanceof User) {
            throw new AuthorizationException('Tenant staff authentication is required.');
        }

        try {
            $updatedUser = resolve(ManageTenantUsers::class)->update(
                $actor,
                $record,
                $data,
                (string) $this->roleName,
            );
        } catch (LogicException $exception) {
            $field = ! (bool) ($this->data['is_active'] ?? $record->is_active)
                ? 'is_active'
                : 'role';
            $this->reject($field, $exception->getMessage());
        }

        $record->setRawAttributes($updatedUser->getAttributes(), true);
        $record->setRelations($updatedUser->getRelations());

        return $record;
    }

    private function reject(string $field, string $message): never
    {
        $this->addError("data.{$field}", $message);
        $this->halt(shouldRollbackDatabaseTransaction: true);
    }
}
