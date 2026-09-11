<?php

namespace App\Filament\Resources\Items\Tables;

use App\Enums\ItemType;
use App\Enums\Permission;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Category;
use App\Models\Item;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ItemsTable
{
    private const VEHICLE_YEAR_MIN = 2000;

    private const VEHICLE_YEAR_MAX = 2026;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'category',
                'vehicleCompatibilities.vehicleModel.vehicleMake',
            ]))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->wrap()
                    ->description(fn (Item $record): ?string => self::packDescription($record)),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (ItemType $state): string => $state->label())
                    ->color(fn (ItemType $state): string => $state === ItemType::Product ? 'info' : 'warning')
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('compatibility_summary')
                    ->label('Compatibility')
                    ->state(fn (Item $record): string => self::compatibilitySummary($record))
                    ->wrap(),

                TextColumn::make('unit_cost')
                    ->label('Unit cost')
                    ->numeric(decimalPlaces: 2)
                    ->alignRight()
                    ->placeholder('—')
                    ->sortable()
                    ->visible(fn (): bool => auth()->user()?->can(Permission::ViewItemUnitCost->value) ?? false),

                TextColumn::make('stock_level')
                    ->label('In stock')
                    ->alignCenter()
                    ->placeholder('not tracked')
                    ->sortable()
                    ->badge()
                    ->state(fn (Item $record): ?string => $record->stockLabel())
                    ->color(fn (Model $record): string => $record->isLowOnStock() ? 'danger' : 'gray'),

                TextColumn::make('low_stock_alert')
                    ->label('Alert at')
                    ->alignCenter()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('On sale screen')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(ItemType::cases())->mapWithKeys(
                        fn (ItemType $type) => [$type->value => $type->label()]
                    )),

                SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all()),

                TernaryFilter::make('is_active')
                    ->label('On the sale screen')
                    ->placeholder('All items'),

                Filter::make('vehicle_compatibility')
                    ->schema([
                        Select::make('make_id')
                            ->label('Make')
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('model_id', null))
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => VehicleMake::query()->orderBy('name')->pluck('name', 'id')->all()),
                        Select::make('model_id')
                            ->label('Model')
                            ->searchable()
                            ->preload()
                            ->options(fn (Get $get): array => VehicleModel::query()
                                ->when(
                                    filled($get('make_id')),
                                    fn (Builder $query): Builder => $query->where('vehicle_make_id', $get('make_id')),
                                )
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all()),
                        TextInput::make('year')
                            ->label('Year')
                            ->numeric()
                            ->integer()
                            ->minValue(self::VEHICLE_YEAR_MIN)
                            ->maxValue(self::VEHICLE_YEAR_MAX),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->compatibleWith(
                        self::nullableInt($data['make_id'] ?? null),
                        self::nullableInt($data['model_id'] ?? null),
                        self::nullableInt($data['year'] ?? null),
                    )),

                Filter::make('low_stock')
                    ->label('Low on stock')
                    ->query(fn (Builder $query): Builder => $query->lowStock())
                    ->toggle(),
            ])
            ->recordActions([
                self::receiveStockAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No inventory yet')
            ->emptyStateDescription('Add products and repair tasks here, or quick-add them straight from the sale screen.');
    }

    private static function compatibilitySummary(Item $record): string
    {
        if ($record->is_universal) {
            return 'Universal';
        }

        $summaries = $record->vehicleCompatibilities
            ->map(fn ($compatibility): ?string => self::compatibilityLine(
                $compatibility->vehicleModel?->vehicleMake?->name,
                $compatibility->vehicleModel?->name,
                $compatibility->year_from,
                $compatibility->year_to,
            ))
            ->filter()
            ->values();

        if ($summaries->isEmpty()) {
            return 'No compatibility set';
        }

        return $summaries->implode(', ');
    }

    private static function compatibilityLine(?string $make, ?string $model, ?int $yearFrom, ?int $yearTo): ?string
    {
        if (blank($model)) {
            return null;
        }

        $label = trim(collect([$make, $model])->filter()->implode(' '));
        $range = match (true) {
            $yearFrom !== null && $yearTo !== null => "{$yearFrom}-{$yearTo}",
            $yearFrom !== null => "{$yearFrom}+",
            $yearTo !== null => "Up to {$yearTo}",
            default => null,
        };

        return filled($range) ? "{$label} {$range}" : $label;
    }

    /**
     * Booking in a delivery: whole packs for anything with packaging described,
     * a loose amount otherwise — and always as a fallback, because a recount
     * has to be typeable in the item's own unit.
     */
    private static function receiveStockAction(): Action
    {
        return Action::make('receiveStock')
            ->label('Receive stock')
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->color('success')
            ->authorize(fn (): bool => ItemResource::canManageStock())
            ->modalHeading(fn (Item $record): string => "Receive stock — {$record->name}")
            ->modalDescription(fn (Item $record): string => self::packDescription($record) ?? 'Type the amount that arrived.')
            ->modalSubmitActionLabel('Add to stock')
            ->schema(fn (Item $record): array => self::receiveStockSchema($record))
            ->action(function (Item $record, array $data): void {
                if ($record->stock_level === null) {
                    $record->update(['stock_level' => 0]);
                }

                if (filled($data['packs'] ?? null)) {
                    $record->receivePacks((float) $data['packs']);
                }

                if (filled($data['measure'] ?? null)) {
                    $record->receiveMeasure((string) $data['measure']);
                }

                Notification::make()
                    ->success()
                    ->title('Stock received')
                    ->body("{$record->name} is now at ".($record->refresh()->stockLabel() ?? '—').'.')
                    ->send();
            });
    }

    /** @return array<int, TextInput> */
    private static function receiveStockSchema(Item $record): array
    {
        $fields = [];
        $contains = $record->packContains();

        if ($contains !== null) {
            $pack = $record->pack_label ?: 'pack';

            $fields[] = TextInput::make('packs')
                ->label('How many '.Str::plural($pack).'?')
                ->helperText(self::packDescription($record))
                ->numeric()
                ->minValue(0.001)
                ->maxValue(9999)
                ->step(0.001)
                ->autofocus();
        }

        $fields[] = TextInput::make('measure')
            ->label(self::looseLabel($record))
            ->helperText($contains === null
                ? 'The amount that arrived, or a corrected count.'
                : 'For a part pack, or to correct the count.')
            ->numeric()
            ->minValue(0.001)
            ->maxValue(999999)
            ->step(0.001)
            ->required(fn (Get $get): bool => blank($get('packs')))
            ->autofocus($contains === null);

        return $fields;
    }

    private static function looseLabel(Item $record): string
    {
        $abbreviation = $record->unit_of_measure->abbreviation();

        return $abbreviation === ''
            ? 'Loose amount'
            : "Loose amount ({$abbreviation})";
    }

    private static function packDescription(Item $record): ?string
    {
        $contains = $record->packContains();

        if ($contains === null) {
            return null;
        }

        return sprintf(
            'One %s = %s %s',
            $record->pack_label ?: 'pack',
            $contains,
            $record->unit_of_measure->abbreviation(),
        );
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
