<?php

namespace App\Filament\Resources\Items\Tables;

use App\Enums\ItemType;
use App\Enums\Permission;
use App\Filament\Resources\Items\ItemResource;
use App\Models\Item;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
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
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->wrap()
                    // The pack size rides along under the name so a mistyped
                    // bottle count is visible without opening the item.
                    ->description(fn (Item $record): ?string => self::packDescription($record)),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (ItemType $state): string => $state->label())
                    ->color(fn (ItemType $state): string => $state === ItemType::Product ? 'info' : 'warning')
                    ->sortable(),

                TextColumn::make('unit_cost')
                    ->label('Unit cost')
                    ->numeric(decimalPlaces: 2)
                    ->alignRight()
                    ->placeholder('—')
                    ->sortable()
                    // Managers must not see costing; only the owner does.
                    ->visible(fn (): bool => auth()->user()?->can(Permission::ViewItemUnitCost->value) ?? false),

                TextColumn::make('stock_level')
                    ->label('In stock')
                    ->alignCenter()
                    ->placeholder('not tracked')
                    ->sortable()
                    ->badge()
                    // Stock reads back in the item's own unit: "32.000 L", "13.000 kg", "7".
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

                TernaryFilter::make('is_active')
                    ->label('On the sale screen')
                    ->placeholder('All items'),

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
            // Admin and Manager keep the shelves stocked; a technician never
            // touches a count. The action is a live endpoint, so this is the
            // gate, not the button's visibility.
            ->authorize(fn (): bool => ItemResource::canManageStock())
            ->modalHeading(fn (Item $record): string => "Receive stock — {$record->name}")
            ->modalDescription(fn (Item $record): string => self::packDescription($record) ?? 'Type the amount that arrived.')
            ->modalSubmitActionLabel('Add to stock')
            ->schema(fn (Item $record): array => self::receiveStockSchema($record))
            ->action(function (Item $record, array $data): void {
                // increment() leaves NULL where it finds it, and NULL means "never
                // counted" — so a first delivery opens the count at zero instead
                // of silently doing nothing.
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
                // The modal speaks the shop's own language: "How many Cartons?"
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
            // Something has to be typed: with no pack count, this is the amount.
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

    /** "One Carton = 16.000 L", or null when the shop has not described the packaging. */
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
            $record->unit_of_measure->abbreviation()
        );
    }
}
