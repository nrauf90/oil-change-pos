<?php

namespace App\Filament\Platform\Resources\ShopAccessSessions\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShopAccessSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('shop.name')->label('Shop')->searchable()->weight('bold'),
                TextColumn::make('platformUser.name')->label('Operator')->searchable(),
                TextColumn::make('started_at')->label('Started')->dateTime('d M Y, g:i A')->sortable(),
                TextColumn::make('ended_at')->label('Ended')->dateTime('d M Y, g:i A')->placeholder('Active')->sortable(),
                TextColumn::make('ip_address')->label('IP address')->placeholder('Unknown'),
                TextColumn::make('reason')->placeholder('No reason provided')->wrap(),
                TextColumn::make('user_agent')
                    ->label('User agent')
                    ->placeholder('Unknown')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->emptyStateIcon('heroicon-o-eye')
            ->emptyStateHeading('No support access sessions');
    }
}
