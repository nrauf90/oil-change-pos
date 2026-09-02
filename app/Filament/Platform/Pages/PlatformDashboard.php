<?php

namespace App\Filament\Platform\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Route;

class PlatformDashboard extends Dashboard
{
    protected static ?string $title = 'Platform overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    public function getColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createShop')
                ->label('Create shop')
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): ?string => Route::has('filament.platform.resources.shops.create')
                    ? route('filament.platform.resources.shops.create')
                    : null)
                ->visible(fn (): bool => Route::has('filament.platform.resources.shops.create')),
        ];
    }
}
