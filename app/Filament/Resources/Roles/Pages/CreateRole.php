<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @var array<int, string> */
    private array $permissionNames = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $groups = $this->permissionGroups();
        $invalidGroups = RoleResource::invalidPermissionGroups($groups);

        foreach ($invalidGroups as $group) {
            $this->addError(
                "data.permission_groups.{$group}",
                'This role includes a permission that is unavailable for this shop.',
            );
        }

        if ($invalidGroups !== []) {
            $this->halt(shouldRollbackDatabaseTransaction: true);
        }

        $this->permissionNames = RoleResource::selectedPermissionNames($groups);
        unset($data['permission_groups']);

        $data['name'] = trim((string) $data['name']);
        $data['description'] = filled($data['description'] ?? null)
            ? trim((string) $data['description'])
            : null;
        $data['guard_name'] = 'web';

        return $data;
    }

    protected function afterCreate(): void
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
