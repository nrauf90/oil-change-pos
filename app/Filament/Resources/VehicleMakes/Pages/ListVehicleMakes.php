<?php

namespace App\Filament\Resources\VehicleMakes\Pages;

use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVehicleMakes extends ListRecords
{
    protected static string $resource = VehicleMakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
