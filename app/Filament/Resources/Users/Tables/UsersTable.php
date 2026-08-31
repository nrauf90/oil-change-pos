<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
                    ->formatStateUsing(fn (string $state): string => Role::tryFrom($state)?->label() ?? $state)
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
                    ->sortable(),

                TextColumn::make('sales_count')
                    ->label('Sales rung up')
                    ->counts('sales')
                    ->alignCenter()
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
                    ->options(collect(Role::cases())->mapWithKeys(
                        fn (Role $role) => [$role->value => $role->label()]
                    ))
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
                    // Deleting yourself would lock you out mid-session.
                    ->hidden(fn (Model $record): bool => $record->is(auth()->user())),
            ])
            ->defaultSort('name');
    }
}
