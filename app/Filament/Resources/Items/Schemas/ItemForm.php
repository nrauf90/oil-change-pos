<?php

namespace App\Filament\Resources\Items\Schemas;

use App\Enums\ItemType;
use App\Enums\Permission;
use App\Enums\UnitOfMeasure;
use App\Models\Category;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;

class ItemForm
{
    private const VEHICLE_YEAR_MIN = 2000;

    private const VEHICLE_YEAR_MAX = 2026;

    public static function configure(Schema $schema): Schema
    {
        $itemSection = Section::make('Item')
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
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if ($state !== ItemType::Product->value) {
                            $set('is_universal', false);
                            $set('vehicleCompatibilities', []);
                        }
                    })
                    ->options(collect(ItemType::cases())->mapWithKeys(
                        fn (ItemType $type) => [$type->value => $type->label()]
                    )),

                Toggle::make('is_active')
                    ->label('Available on the sale screen')
                    ->default(true)
                    ->helperText('Switch off to retire an item without losing its sales history.'),

                Select::make('category_id')
                    ->label('Category')
                    ->searchable()
                    ->preload()
                    ->placeholder('No category')
                    ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label('Category name')
                            ->required()
                            ->maxLength(100),
                    ])
                    ->createOptionAction(fn (Action $action): Action => $action->visible(
                        fn (): bool => auth()->user()?->isAdmin() ?? false,
                    ))
                    ->createOptionUsing(fn (array $data): int => Category::query()->firstOrCreate([
                        'name' => $data['name'],
                    ])->getKey()),

                Toggle::make('is_universal')
                    ->label('Universal fit')
                    ->visible(fn (Get $get): bool => $get('type') === ItemType::Product->value)
                    ->live()
                    ->default(true)
                    ->afterStateUpdated(function (Set $set, bool $state): void {
                        if ($state) {
                            $set('vehicleCompatibilities', []);
                        }
                    })
                    ->helperText('Leave this on when the product fits any vehicle. Switch it off to enter make, model, and year ranges.'),
            ]);

        $vehicleCompatibilitySection = Section::make('Vehicle compatibility')
            ->description('Use exact makes, models, and model years for vehicle-specific products.')
            ->visible(fn (Get $get): bool => self::showsVehicleCompatibility($get))
            ->schema([
                Repeater::make('vehicleCompatibilities')
                    ->relationship()
                    ->compact()
                    ->defaultItems(0)
                    ->required(fn (Get $get): bool => self::showsVehicleCompatibility($get))
                    ->minItems(fn (Get $get): int => self::showsVehicleCompatibility($get) ? 1 : 0)
                    ->itemLabel('Vehicle')
                    ->itemNumbers()
                    ->addActionLabel('Add compatibility')
                    ->addActionAlignment(Alignment::Start)
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                        $vehicleMakeId = VehicleModel::query()
                            ->whereKey($data['vehicle_model_id'] ?? null)
                            ->value('vehicle_make_id');

                        return [
                            ...$data,
                            'vehicle_make_id' => $vehicleMakeId,
                        ];
                    })
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::stripVehicleMakeId($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::stripVehicleMakeId($data))
                    ->schema([
                        Select::make('vehicle_make_id')
                            ->label('Make')
                            ->required()
                            ->live()
                            ->dehydrated(false)
                            ->searchable()
                            ->preload()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('vehicle_model_id', null))
                            ->options(fn (): array => VehicleMake::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Make name')
                                    ->required()
                                    ->maxLength(100),
                            ])
                            ->createOptionAction(fn (Action $action): Action => $action->visible(
                                fn (): bool => auth()->user()?->isAdmin() ?? false,
                            ))
                            ->createOptionUsing(fn (array $data): int => VehicleMake::query()->firstOrCreate([
                                'name' => $data['name'],
                            ])->getKey()),

                        Select::make('vehicle_model_id')
                            ->label('Model')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (Get $get): bool => blank($get('vehicle_make_id')))
                            ->options(fn (Get $get): array => VehicleModel::query()
                                ->when(
                                    filled($get('vehicle_make_id')),
                                    fn ($query) => $query->where('vehicle_make_id', $get('vehicle_make_id')),
                                )
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Model name')
                                    ->required()
                                    ->maxLength(100),
                            ])
                            ->createOptionAction(fn (Action $action): Action => $action->visible(
                                fn (): bool => auth()->user()?->isAdmin() ?? false,
                            ))
                            ->createOptionUsing(function (Select $component, array $data): int {
                                $vehicleMakeId = $component->getContainer()->getState()['vehicle_make_id'] ?? null;

                                return VehicleModel::query()->firstOrCreate([
                                    'vehicle_make_id' => $vehicleMakeId,
                                    'name' => $data['name'],
                                ])->getKey();
                            }),

                        TextInput::make('year_from')
                            ->label('From year')
                            ->numeric()
                            ->integer()
                            ->minValue(self::VEHICLE_YEAR_MIN)
                            ->maxValue(self::VEHICLE_YEAR_MAX)
                            ->placeholder((string) self::VEHICLE_YEAR_MIN),

                        TextInput::make('year_to')
                            ->label('To year')
                            ->numeric()
                            ->integer()
                            ->minValue(self::VEHICLE_YEAR_MIN)
                            ->maxValue(self::VEHICLE_YEAR_MAX)
                            ->gte('year_from')
                            ->placeholder((string) self::VEHICLE_YEAR_MAX),
                    ]),
            ]);

        $costingSection = Section::make('Costing')
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
            ]);

        $countingSection = Section::make('How it is counted')
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
            ]);

        $packagingSection = Section::make('How it is bought')
            ->key('packaging')
            ->description('Describe one pack, so a delivery can be booked in without doing sums on paper.')
            ->columns(3)
            ->visible(fn (Get $get): bool => self::isMeasured($get))
            ->schema([
                TextInput::make('pack_label')
                    ->label('Pack name')
                    ->maxLength(50)
                    ->live(onBlur: true)
                    ->placeholder('e.g. Carton'),

                TextInput::make('units_per_pack')
                    ->label('Units in one pack')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(9999)
                    ->live(onBlur: true)
                    ->helperText('e.g. bottles in a carton'),

                TextInput::make('measure_per_unit')
                    ->label('Amount in each unit')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(99999)
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): string => 'e.g. '.self::packMeasureExample($get)),

                Placeholder::make('pack_size')
                    ->label('Pack summary')
                    ->columnSpanFull()
                    ->content(fn (Get $get): string => self::packSummary($get)),
            ]);

        $stockSection = Section::make('Stock')
            ->description('Leave blank for items you do not count.')
            ->columns(2)
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
            ]);

        return $schema
            ->columns(1)
            ->components([
                Grid::make([
                    'default' => 1,
                    'lg' => 2,
                ])->schema([
                    $itemSection,
                    $costingSection,
                ]),

                Grid::make([
                    'default' => 1,
                    'lg' => 2,
                ])->schema([
                    $countingSection,
                    $stockSection,
                ]),

                $packagingSection,
                $vehicleCompatibilitySection,
            ]);
    }

    private static function showsVehicleCompatibility(Get $get): bool
    {
        return $get('type') === ItemType::Product->value && ! $get('is_universal');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stripVehicleMakeId(array $data): array
    {
        unset($data['vehicle_make_id']);

        return $data;
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

    private static function packMeasureExample(Get $get): string
    {
        return match (self::unit($get)) {
            UnitOfMeasure::Litre => '4 L per bottle',
            UnitOfMeasure::Kilogram => '13 kg per cylinder',
            default => '1 unit',
        };
    }

    private static function packSummary(Get $get): string
    {
        $units = (float) $get('units_per_pack');
        $per = (float) $get('measure_per_unit');
        $unit = self::unit($get);

        if ($units <= 0 || $per <= 0) {
            return 'Fill in both numbers to see the pack summary.';
        }

        $label = trim((string) $get('pack_label')) ?: 'Pack';

        return sprintf('1 %s = %s %s', $label, number_format($units * $per, $unit->precision(), '.', ''), $unit->abbreviation());
    }
}
