<?php

namespace App\Filament\Pages;

use App\Enums\DashboardPeriod;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class AdminDashboard extends Dashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            ToggleButtons::make('period')
                ->label('Reporting period')
                ->options(collect(DashboardPeriod::cases())->mapWithKeys(
                    fn (DashboardPeriod $period): array => [$period->value => $period->label()],
                ))
                ->default(DashboardPeriod::Today->value)
                ->grouped()
                ->inline(),
        ]);
    }
}
