<?php

namespace App\Filament\Resources\VehicleMakes\Schemas;

use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VehicleMakeForm
{
    public static function configure(Schema $schema): Schema
    {
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

                    Repeater::make('vehicleModels')
                        ->relationship()
                        ->defaultItems(0)
                        ->columnSpanFull()
                        ->addActionLabel('Add model')
                        ->deleteAction(fn (Action $action): Action => VehicleMakeResource::configureVehicleModelDeleteAction($action))
                        ->schema([
                            TextInput::make('name')
                                ->label('Model name')
                                ->required()
                                ->maxLength(100),
                        ]),
                ]),
        ]);
    }
}
