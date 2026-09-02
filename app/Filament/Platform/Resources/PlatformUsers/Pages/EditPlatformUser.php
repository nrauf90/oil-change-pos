<?php

namespace App\Filament\Platform\Resources\PlatformUsers\Pages;

use App\Filament\Platform\Resources\PlatformUsers\PlatformUserResource;
use App\Filament\Platform\Resources\PlatformUsers\Tables\PlatformUsersTable;
use App\Models\Central\PlatformUser;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class EditPlatformUser extends EditRecord
{
    protected static string $resource = PlatformUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlatformUsersTable::deleteAction(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $hasActiveState = array_key_exists('is_active', $data);
        $isActive = (bool) ($data['is_active'] ?? false);
        unset($data['is_active'], $data['role'], $data['last_login_at']);

        try {
            $attributes = $record->getConnection()->transaction(function () use (
                $record,
                $data,
                $hasActiveState,
                $isActive,
            ): array {
                $platformUser = PlatformUser::query()
                    ->whereKey($record->getKey())
                    ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
                    ->firstOrFail();

                if ($hasActiveState && $isActive !== $platformUser->is_active) {
                    $isActive ? $platformUser->activate() : $platformUser->deactivate();
                }

                $platformUser->fill($data)->save();

                return $platformUser->getAttributes();
            });
        } catch (LogicException $exception) {
            $this->addError('data.is_active', $exception->getMessage());
            $this->halt(shouldRollbackDatabaseTransaction: true);
        }

        $record->setRawAttributes($attributes, true);

        return $record;
    }
}
