<?php

namespace App\Filament\Platform\Resources\ShopAccessSessions;

use App\Filament\Platform\Resources\PlatformUsers\PlatformUserResource;
use App\Filament\Platform\Resources\ShopAccessSessions\Pages\ListShopAccessSessions;
use App\Filament\Platform\Resources\ShopAccessSessions\Tables\ShopAccessSessionsTable;
use App\Models\Central\ShopAccessSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ShopAccessSessionResource extends Resource
{
    protected static ?string $model = ShopAccessSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static ?string $navigationLabel = 'Support access';

    protected static ?string $modelLabel = 'support access session';

    protected static ?string $pluralModelLabel = 'support access sessions';

    protected static ?int $navigationSort = 80;

    public static function table(Table $table): Table
    {
        return ShopAccessSessionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListShopAccessSessions::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['shop', 'platformUser'])
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    public static function canViewAny(): bool
    {
        return PlatformUserResource::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
