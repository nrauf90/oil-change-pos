<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\ManageTenantUsers;
use App\Enums\Role;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role as RoleModel;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use LogicException;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Staff member')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Model $record): string => '@'.$record->username),

                TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Role::tryFrom($state)?->label() ?? Str::headline($state))
                    ->color(fn (string $state): string => match ($state) {
                        Role::Admin->value => 'danger',
                        Role::Manager->value => 'warning',
                        default => 'info',
                    }),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('last_login_at')
                    ->label('Last signed in')
                    ->dateTime('d M Y, g:i A')
                    ->placeholder('Never')
                    ->visibleFrom('md')
                    ->sortable(),

                TextColumn::make('sales_count')
                    ->label('Sales rung up')
                    ->counts('sales')
                    ->alignCenter()
                    ->visibleFrom('lg')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Role')
                    ->options(fn (): array => RoleModel::query()
                        ->where('guard_name', 'web')
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (RoleModel $role): array => [
                            $role->name => RoleResource::displayName($role),
                        ])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', $data['value']))
                    )),

                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All staff')
                    ->trueLabel('Active only')
                    ->falseLabel('Deactivated only'),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ])
            ->defaultSort('name')
            ->stackedOnMobile();
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->using(static function (User $record): bool {
                $actor = Auth::user();

                if (! $actor instanceof User) {
                    return false;
                }

                try {
                    return resolve(ManageTenantUsers::class)->delete($actor, $record);
                } catch (AuthorizationException|LogicException|QueryException) {
                    return false;
                }
            })
            ->failureNotificationTitle('Staff member could not be deleted')
            ->failureNotificationBody(
                'This account is now the final active admin or is required by existing shop records.',
            )
            ->hidden(fn (Model $record): bool => $record->is(auth()->user()) || UserResource::isLastAdmin($record));
    }
}
