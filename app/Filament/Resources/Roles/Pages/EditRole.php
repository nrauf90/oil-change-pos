<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @var array<int, string> */
    private array $permissionNames = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['description'] = RoleResource::displayDescription($this->record);
        $data['permission_groups'] = RoleResource::permissionGroupState($this->record);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $rawName = trim((string) ($this->data['name'] ?? ''));

        if (RoleResource::isBuiltIn($this->record) && $rawName !== $this->record->name) {
            $this->addError('data.name', 'Built-in role names cannot be changed.');
            $this->halt(shouldRollbackDatabaseTransaction: true);
        }

        if (RoleResource::isAdministrator($this->record)) {
            $this->permissionNames = RoleResource::registeredPermissionNames();
        } else {
            $groups = $this->permissionGroups();
            $unavailable = RoleResource::unavailableAssignedPermissionNames($this->record);
            $invalidGroups = RoleResource::invalidPermissionGroups($groups, $unavailable);

            foreach ($invalidGroups as $group) {
                $this->addError(
                    "data.permission_groups.{$group}",
                    'This role includes a permission that is unavailable for this shop.',
                );
            }

            if ($invalidGroups !== []) {
                $this->halt(shouldRollbackDatabaseTransaction: true);
            }

            $selected = array_intersect(
                RoleResource::selectedPermissionNames($groups),
                RoleResource::effectivePermissionNames(),
            );
            $this->permissionNames = array_values(array_unique([...$selected, ...$unavailable]));
        }

        unset($data['permission_groups']);

        if (RoleResource::isBuiltIn($this->record)) {
            unset($data['name'], $data['description']);
        } else {
            $data['name'] = $rawName;
            $data['description'] = filled($data['description'] ?? null)
                ? trim((string) $data['description'])
                : null;
        }

        $data['guard_name'] = 'web';

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncPermissions($this->permissionNames);
    }

    /** @return array<string, mixed> */
    private function permissionGroups(): array
    {
        $groups = $this->data['permission_groups'] ?? [];

        return is_array($groups) ? $groups : [];
    }
}
