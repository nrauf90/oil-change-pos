<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Role')
                    ->state(fn (Role $record): string => RoleResource::displayName($record))
                    ->description(fn (Role $record): ?string => RoleResource::displayDescription($record))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->wrap(),

                TextColumn::make('type')
                    ->badge()
                    ->state(fn (Role $record): string => RoleResource::isBuiltIn($record) ? 'Built-in' : 'Custom')
                    ->color(fn (string $state): string => $state === 'Built-in' ? 'info' : 'gray'),

                TextColumn::make('active_users_count')
                    ->label('Active staff')
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->stackedOnMobile()
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading('No custom roles yet')
            ->emptyStateDescription('Create a role to give staff the exact access they need.')
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }
}
