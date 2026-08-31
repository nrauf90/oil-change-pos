<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Enums\ItemType;
use App\Enums\Permission;
use App\Enums\UnitOfMeasure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Item')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Item name')
                        ->required()
                        ->maxLength(150)
                        ->unique(ignoreRecord: true)
                        ->columnSpanFull()
                        ->placeholder('e.g. ZIC X7 10W-40 Full Synthetic (4L)'),

                    Radio::make('type')
                        ->label('Type')
                        ->required()
                        ->inline()
                        ->live()
                        ->default(ItemType::Product->value)
                        ->options(collect(ItemType::cases())->mapWithKeys(
                            fn (ItemType $type) => [$type->value => $type->label()]
                        )),

                    Toggle::make('is_active')
                        ->label('Available on the sale screen')
                        ->default(true)
                        ->helperText('Switch off to retire an item without losing its sales history.'),
                ]),

            Section::make('Costing')
                ->description('Reference figures for the owner. None of this ever sets a sale price — the counter types every price by hand.')
                ->columns(2)
                ->visible(fn (): bool => auth()->user()?->can(Permission::ViewItemUnitCost->value) ?? false)
                ->schema([
                    TextInput::make('unit_cost')
                        ->label('Unit cost')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(99999999)
                        ->prefix('Rs')
                        ->disabled(fn (): bool => ! (auth()->user()?->can(Permission::SetItemUnitCost->value) ?? false))
                        ->dehydrated(fn (): bool => auth()->user()?->can(Permission::SetItemUnitCost->value) ?? false)
                        ->helperText('What the shop pays for it. Used only for margin analysis.'),
                ]),

            Section::make('How it is counted')
                ->description('Filters come off the shelf whole. Oil is poured by the litre and AC gas is charged by the kilogram, so those are held as decimals.')
                ->columns(2)
                ->schema([
                    Select::make('unit_of_measure')
                        ->label('Counted in')
                        ->required()
                        ->live()
                        ->selectablePlaceholder(false)
                        ->default(UnitOfMeasure::Piece->value)
                        ->options(collect(UnitOfMeasure::cases())->mapWithKeys(
                            fn (UnitOfMeasure $unit) => [$unit->value => $unit->label()]
                        )),
                ]),

            // Packaging is how the supplier delivers a measured item — a carton of
            // four 4-litre bottles. It says nothing about a filter, so it is hidden
            // outright for pieces rather than sitting there inviting a wrong answer.
            Section::make('How it is bought')
                ->key('packaging')
                ->description('Describe one pack, so a delivery can be booked in without doing sums on paper.')
                ->columns(3)
                ->visible(fn (Get $get): bool => self::isMeasured($get))
                ->schema([
                    TextInput::make('pack_label')
                        ->label('Pack is called')
                        ->maxLength(50)
                        ->live(onBlur: true)
                        ->placeholder('e.g. Carton'),

                    TextInput::make('units_per_pack')
                        ->label('Units per pack')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(9999)
                        ->live(onBlur: true)
                        ->helperText('Bottles in a carton, cylinders in a delivery.'),

                    TextInput::make('measure_per_unit')
                        ->label('Amount per unit')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(99999)
                        ->live(onBlur: true)
                        ->helperText('Litres in one bottle, kilograms in one cylinder.'),

                    Placeholder::make('pack_size')
                        ->label('One pack holds')
                        ->columnSpanFull()
                        // Reflecting the sum back is what makes a mistyped bottle
                        // count obvious before it is saved.
                        ->content(fn (Get $get): string => self::packSummary($get)),
                ]),

            Section::make('Stock')
                ->description('Leave blank for items you do not count.')
                ->columns(2)
                // A repair task is normally labour with no shelf to count it on —
                // but a measured repair, like an AC gas refill, empties a cylinder.
                ->visible(fn (Get $get): bool => $get('type') === ItemType::Product->value || self::isMeasured($get))
                ->schema([
                    TextInput::make('stock_level')
                        ->label(fn (Get $get): string => 'Current stock level'.self::unitSuffix($get))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(999999)
                        ->step(0.001)
                        ->helperText('Blank means this item is not stock-tracked.'),

                    TextInput::make('low_stock_alert')
                        ->label(fn (Get $get): string => 'Low stock alert at'.self::unitSuffix($get))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(999999)
                        ->step(0.001)
                        ->helperText('Flag the item once stock falls to this amount or below.'),
                ]),
        ]);
    }

    private static function unit(Get $get): UnitOfMeasure
    {
        $value = $get('unit_of_measure');

        return ($value instanceof UnitOfMeasure ? $value : UnitOfMeasure::tryFrom((string) $value))
            ?? UnitOfMeasure::Piece;
    }

    private static function isMeasured(Get $get): bool
    {
        return self::unit($get)->isMeasured();
    }

    private static function unitSuffix(Get $get): string
    {
        $abbreviation = self::unit($get)->abbreviation();

        return $abbreviation === '' ? '' : " ({$abbreviation})";
    }

    private static function packSummary(Get $get): string
    {
        $units = (float) $get('units_per_pack');
        $per = (float) $get('measure_per_unit');
        $unit = self::unit($get);

        if ($units <= 0 || $per <= 0) {
            return 'Fill in both numbers to see the pack size.';
        }

        $label = trim((string) $get('pack_label')) ?: 'pack';

        return sprintf('One %s = %s %s', $label, number_format($units * $per, $unit->precision(), '.', ''), $unit->abbreviation());
    }
}
