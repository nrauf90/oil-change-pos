<?php

namespace App\Filament\Resources\VehicleMakes\Schemas;

use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VehicleMakeForm
{
    public static function configure(Schema $schema): Schema
    {
        $schema->columns(1);

        return $schema->components([
            Section::make('Vehicle make')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Make name')
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true)
                        ->columnSpanFull(),

                    Section::make('Vehicle models')
                        ->columnSpanFull()
                        ->schema([
                            View::make('filament.resources.vehicle-makes.model-list-styles')
                                ->extraAttributes(['style' => 'display: none;']),

                            Grid::make(4)
                                ->extraAttributes(['class' => 'vehicle-model-add-row'])
                                ->schema([
                                    TextInput::make('newVehicleModelName')
                                        ->label('New model name')
                                        ->maxLength(100)
                                        ->dehydrated(false)
                                        ->columnSpan(3),

                                    Actions::make([
                                        Action::make('addModel')
                                            ->label('Add model')
                                            ->extraAttributes(['class' => 'vehicle-model-add-button'])
                                            ->action(function (Get $get, Set $set): void {
                                                $name = Str::squish((string) $get('newVehicleModelName'));
                                                $vehicleModels = $get->array('vehicleModels');

                                                if (blank($name)) {
                                                    throw ValidationException::withMessages([
                                                        'data.newVehicleModelName' => 'The new model name field is required.',
                                                    ]);
                                                }

                                                if (collect($vehicleModels)->contains(
                                                    fn (array $vehicleModel): bool => Str::lower(Str::squish((string) ($vehicleModel['name'] ?? ''))) === Str::lower($name),
                                                )) {
                                                    throw ValidationException::withMessages([
                                                        'data.newVehicleModelName' => 'This vehicle model already exists for the selected make.',
                                                    ]);
                                                }

                                                $set('vehicleModels', [
                                                    ...$vehicleModels,
                                                    (string) Str::uuid() => ['name' => $name],
                                                ]);
                                                $set('newVehicleModelName', null);
                                            }),
                                    ])
                                        ->key('vehicleModelActions')
                                        ->extraAttributes(['class' => 'vehicle-model-actions'])
                                        ->verticallyAlignEnd()
                                        ->columnSpan(1),
                                ]),

                            Repeater::make('vehicleModels')
                                ->hiddenLabel()
                                ->relationship()
                                ->extraAttributes(['class' => 'vehicle-model-list'])
                                ->defaultItems(0)
                                ->addable(false)
                                ->reorderable(false)
                                ->compact()
                                ->table([
                                    TableColumn::make('Model')->hiddenHeaderLabel(),
                                ])
                                ->deleteAction(fn (Action $action): Action => VehicleMakeResource::configureVehicleModelDeleteAction($action))
                                ->schema([
                                    Hidden::make('name'),
                                    View::make('filament.resources.vehicle-makes.model-name')
                                        ->viewData(fn (Get $get): array => [
                                            'name' => $get->string('name'),
                                        ]),
                                ]),
                        ]),
                ]),
        ]);
    }
}
