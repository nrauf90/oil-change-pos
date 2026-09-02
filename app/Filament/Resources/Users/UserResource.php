<?php

namespace App\Filament\Resources\Users;

use App\Enums\Permission;
use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $modelLabel = 'staff member';

    protected static ?string $pluralModelLabel = 'staff';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('roles');
    }

    /* -------- Authorization: one named permission per action -------- */

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(Permission::ViewAnyUser->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(Permission::CreateUser->value) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can(Permission::UpdateUser->value) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->is(auth()->user())
            && ! static::isLastAdmin($record)
            && (auth()->user()?->can(Permission::DeleteUser->value) ?? false);
    }

    /**
     * Is this record the shop's last way back into the back office?
     *
     * Only *active* admins are counted: a deactivated one cannot sign in, so
     * they are no route back. Demote or deactivate the last of them and the
     * next request fails User::canAccessPanel() with no way to reach
     * /admin/users again — recovery means tinker on the till.
     *
     * The question is asked of the record, never of "is this me": users.update
     * is data, so a second account holding it must be stopped just the same.
     */
    public static function isLastAdmin(?Model $record): bool
    {
        if (! $record instanceof User || ! $record->hasRole(Role::Admin->value)) {
            return false;
        }

        return ! User::query()
            ->whereKeyNot($record->getKey())
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $query) => $query->where('name', Role::Admin->value))
            ->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
