<?php

namespace App\Filament\Platform\Resources\PlatformUsers\Pages;

use App\Filament\Platform\Resources\PlatformUsers\PlatformUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlatformUsers extends ListRecords
{
    protected static string $resource = PlatformUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
