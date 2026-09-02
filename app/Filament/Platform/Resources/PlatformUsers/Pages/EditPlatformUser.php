<?php

namespace App\Filament\Platform\Resources\PlatformUsers\Pages;

use App\Actions\ManagePlatformUsers;
use App\Filament\Platform\Resources\PlatformUsers\PlatformUserResource;
use App\Filament\Platform\Resources\PlatformUsers\Tables\PlatformUsersTable;
use App\Models\Central\PlatformUser;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
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
        $actor = Auth::guard('platform')->user();

        if (! $actor instanceof PlatformUser || ! $record instanceof PlatformUser) {
            throw new AuthorizationException('Platform administrator authentication is required.');
        }

        try {
            $platformUser = resolve(ManagePlatformUsers::class)->update($actor, $record, $data);
        } catch (LogicException $exception) {
            $this->addError('data.is_active', $exception->getMessage());
            $this->halt(shouldRollbackDatabaseTransaction: true);
        }

        $record->setRawAttributes($platformUser->getAttributes(), true);

        return $record;
    }
}
