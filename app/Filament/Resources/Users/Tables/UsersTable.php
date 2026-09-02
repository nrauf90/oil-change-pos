<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role as RoleModel;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
                DeleteAction::make()
                    ->hidden(fn (Model $record): bool => $record->is(auth()->user()) || UserResource::isLastAdmin($record)),
            ])
            ->defaultSort('name')
            ->stackedOnMobile();
    }
}
