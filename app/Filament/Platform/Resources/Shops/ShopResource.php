<?php

namespace App\Filament\Platform\Resources\Shops;

use App\Enums\ShopStatus;
use App\Filament\Platform\Resources\PlatformUsers\PlatformUserResource;
use App\Filament\Platform\Resources\Shops\Pages\CreateShop;
use App\Filament\Platform\Resources\Shops\Pages\ListShops;
use App\Filament\Platform\Resources\Shops\Pages\ViewShop;
use App\Filament\Platform\Resources\Shops\Schemas\ShopForm;
use App\Filament\Platform\Resources\Shops\Tables\ShopsTable;
use App\Models\Central\Shop;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ShopResource extends Resource
{
    protected static ?string $model = Shop::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'Shops';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ShopForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])
                ->schema([
                    Section::make('Shop')
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            TextEntry::make('name'),
                            TextEntry::make('slug')->label('URL slug'),
                            TextEntry::make('status')
                                ->badge()
                                ->formatStateUsing(static fn (ShopStatus $state): string => self::statusLabel($state))
                                ->icon(static fn (ShopStatus $state): Heroicon => self::statusIcon($state))
                                ->color(static fn (ShopStatus $state): string => self::statusColor($state)),
                            TextEntry::make('timezone'),
                            TextEntry::make('currency'),
                            TextEntry::make('provisioning_failure_message')
                                ->label('Provisioning issue')
                                ->placeholder('None')
                                ->columnSpanFull(),
                        ]),

                    Section::make('Owner and health')
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            TextEntry::make('owner.name')->label('Owner'),
                            TextEntry::make('owner.username')->label('Username'),
                            TextEntry::make('owner.email')->label('Email')->placeholder('Not provided'),
                            TextEntry::make('health_connection')
                                ->label('Connection')
                                ->state(static fn (Shop $record): string => (string) data_get(
                                    $record->healthSnapshot?->summary,
                                    'connection_status',
                                    'unavailable',
                                ))
                                ->badge()
                                ->formatStateUsing(static fn (string $state): string => ucfirst($state))
                                ->color(static fn (string $state): string => $state === 'healthy' ? 'success' : 'danger'),
                            TextEntry::make('healthSnapshot.migration_status')
                                ->label('Migrations')
                                ->placeholder('Unavailable')
                                ->badge()
                                ->color(static fn (?string $state): string => match ($state) {
                                    'current' => 'success',
                                    'pending' => 'warning',
                                    default => 'danger',
                                }),
                            TextEntry::make('seed_status')
                                ->label('Seed data')
                                ->state(static fn (Shop $record): string => (string) data_get(
                                    $record->healthSnapshot?->summary,
                                    'seed_status',
                                    'unknown',
                                ))
                                ->badge()
                                ->formatStateUsing(static fn (string $state): string => ucfirst($state))
                                ->color(static fn (string $state): string => $state === 'current' ? 'success' : 'warning'),
                            TextEntry::make('healthSnapshot.last_successful_connection_at')
                                ->label('Last connected')
                                ->dateTime('d M Y, g:i A')
                                ->placeholder('Never'),
                            TextEntry::make('healthSnapshot.last_activity_at')
                                ->label('Last activity')
                                ->dateTime('d M Y, g:i A')
                                ->placeholder('No activity'),
                        ]),

                    Section::make('This month')
                        ->description('Statistics come only from this shop.')
                        ->columnSpan(['default' => 1, 'lg' => 2])
                        ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                        ->schema([
                            self::statisticEntry('sales', 'Sales'),
                            self::statisticEntry('expenses', 'Expenses'),
                            self::statisticEntry('gross_margin', 'Gross margin'),
                            TextEntry::make('statistics_transaction_count')
                                ->label('Transactions')
                                ->state(static fn (ViewShop $livewire): ?int => self::statistic(
                                    $livewire,
                                    'transaction_count',
                                ))
                                ->placeholder('Unavailable'),
                            TextEntry::make('statistics_active_users')
                                ->label('Active users')
                                ->state(static fn (ViewShop $livewire): ?int => self::statistic(
                                    $livewire,
                                    'active_users',
                                ))
                                ->placeholder('Unavailable'),
                            TextEntry::make('statistics_inventory_count')
                                ->label('Inventory items')
                                ->state(static fn (ViewShop $livewire): ?int => self::statistic(
                                    $livewire,
                                    'inventory_count',
                                ))
                                ->placeholder('Unavailable'),
                            TextEntry::make('statistics_last_activity_at')
                                ->label('Last activity')
                                ->state(static fn (ViewShop $livewire): ?string => self::statistic(
                                    $livewire,
                                    'last_activity_at',
                                ))
                                ->dateTime('d M Y, g:i A')
                                ->placeholder('No activity')
                                ->columnSpan(['default' => 1, 'xl' => 2]),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return ShopsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShops::route('/'),
            'create' => CreateShop::route('/create'),
            'view' => ViewShop::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['owner', 'healthSnapshot', 'features']);
    }

    public static function enabledFeatureCount(Shop $shop): int
    {
        $features = $shop->relationLoaded('features')
            ? $shop->features
            : $shop->features()->get();
        $storedStates = $features
            ->pluck('enabled', 'module_key')
            ->map(static fn (mixed $enabled): bool => (bool) $enabled)
            ->all();

        return resolve(ModuleRegistry::class)
            ->all()
            ->reject(static fn (Module $module): bool => $module->isCore())
            ->filter(static fn (Module $module): bool => $storedStates[$module->key()]
                ?? $module->enabledByDefault())
            ->count();
    }

    public static function canViewAny(): bool
    {
        return PlatformUserResource::canViewAny();
    }

    public static function canCreate(): bool
    {
        return PlatformUserResource::canCreate();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Shop && PlatformUserResource::canViewAny();
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

    public static function statusLabel(ShopStatus $status): string
    {
        return match ($status) {
            ShopStatus::Active => 'Active',
            ShopStatus::Provisioning => 'Provisioning',
            ShopStatus::Suspended => 'Suspended',
            ShopStatus::Failed => 'Failed',
        };
    }

    public static function statusColor(ShopStatus $status): string
    {
        return match ($status) {
            ShopStatus::Active => 'success',
            ShopStatus::Provisioning => 'warning',
            ShopStatus::Suspended => 'gray',
            ShopStatus::Failed => 'danger',
        };
    }

    public static function statusIcon(ShopStatus $status): Heroicon
    {
        return match ($status) {
            ShopStatus::Active => Heroicon::CheckCircle,
            ShopStatus::Provisioning => Heroicon::Clock,
            ShopStatus::Suspended => Heroicon::PauseCircle,
            ShopStatus::Failed => Heroicon::ExclamationTriangle,
        };
    }

    private static function statisticEntry(string $key, string $label): TextEntry
    {
        return TextEntry::make('statistics_'.$key)
            ->label($label)
            ->state(static function (ViewShop $livewire) use ($key): ?string {
                $value = self::statistic($livewire, $key);

                return is_string($value) ? $value : null;
            })
            ->formatStateUsing(static fn (string $state, Shop $record): string => $record->currency.' '.number_format(
                (float) $state,
                2,
            ))
            ->placeholder('Unavailable');
    }

    private static function statistic(ViewShop $livewire, string $key): int|string|null
    {
        $value = $livewire->tenantStatistics[$key] ?? null;

        return is_int($value) || is_string($value) ? $value : null;
    }
}
