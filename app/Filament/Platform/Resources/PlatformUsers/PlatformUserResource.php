<?php

namespace App\Filament\Platform\Resources\PlatformUsers;

use App\Filament\Platform\Resources\PlatformUsers\Pages\CreatePlatformUser;
use App\Filament\Platform\Resources\PlatformUsers\Pages\EditPlatformUser;
use App\Filament\Platform\Resources\PlatformUsers\Pages\ListPlatformUsers;
use App\Filament\Platform\Resources\PlatformUsers\Schemas\PlatformUserForm;
use App\Filament\Platform\Resources\PlatformUsers\Tables\PlatformUsersTable;
use App\Models\Central\PlatformUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class PlatformUserResource extends Resource
{
    protected static ?string $model = PlatformUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Platform users';

    protected static ?string $modelLabel = 'platform user';

    protected static ?string $pluralModelLabel = 'platform users';

    protected static ?int $navigationSort = 90;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PlatformUserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlatformUsersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
            ->addSelect([
                'active_super_admin_count' => PlatformUser::query()
                    ->selectRaw('count(*)')
                    ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
                    ->where('is_active', true),
            ]);
    }

    public static function canViewAny(): bool
    {
        return self::currentUserIsActiveSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return self::currentUserIsActiveSuperAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return self::currentUserIsActiveSuperAdmin() && self::isManagedRecord($record);
    }

    public static function canDelete(Model $record): bool
    {
        return self::currentUserIsActiveSuperAdmin() && self::isManagedRecord($record);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function isCurrentUser(Model $record): bool
    {
        $currentUser = Auth::guard('platform')->user();

        return $currentUser instanceof PlatformUser
            && $record instanceof PlatformUser
            && (string) $record->getKey() === (string) $currentUser->getKey();
    }

    public static function isFinalActiveSuperAdmin(?Model $record): bool
    {
        if (! $record instanceof PlatformUser
            || $record->role !== PlatformUser::ROLE_SUPER_ADMIN
            || ! $record->is_active) {
            return false;
        }

        $loadedCount = $record->getAttribute('active_super_admin_count');

        if (is_numeric($loadedCount)) {
            return (int) $loadedCount === 1;
        }

        return ! PlatformUser::query()
            ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($record->getKey())
            ->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformUsers::route('/'),
            'create' => CreatePlatformUser::route('/create'),
            'edit' => EditPlatformUser::route('/{record}/edit'),
        ];
    }

    private static function currentUserIsActiveSuperAdmin(): bool
    {
        $currentUser = Auth::guard('platform')->user();

        if (! $currentUser instanceof PlatformUser) {
            return false;
        }

        $freshPlatformUser = PlatformUser::query()
            ->whereKey($currentUser->getKey())
            ->where('is_active', true)
            ->where('role', PlatformUser::ROLE_SUPER_ADMIN)
            ->first();

        if (! $freshPlatformUser instanceof PlatformUser) {
            return false;
        }

        Auth::guard('platform')->setUser($freshPlatformUser);

        return true;
    }

    private static function isManagedRecord(Model $record): bool
    {
        return $record instanceof PlatformUser
            && $record->role === PlatformUser::ROLE_SUPER_ADMIN;
    }
}
