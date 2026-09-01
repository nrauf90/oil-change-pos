<?php

namespace App\Filament\Resources\VehicleMakes\Tables;

use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use App\Models\VehicleMake;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VehicleMakesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('vehicleModels')->withCount('vehicleModels'))
            ->columns([
                TextColumn::make('name')
                    ->label('Make')
                    ->sortable()
                    ->weight('bold')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $matching) use ($search): void {
                            $matching
                                ->where('vehicle_makes.name', 'like', "%{$search}%")
                                ->orWhereHas('vehicleModels', fn (Builder $models): Builder => $models->where('name', 'like', "%{$search}%"));
                        });
                    }),

                TextColumn::make('models_summary')
                    ->label('Models')
                    ->state(fn (VehicleMake $record): string => $record->vehicleModels->pluck('name')->sort()->implode(', '))
                    ->placeholder('No models yet')
                    ->wrap(),

                TextColumn::make('vehicle_models_count')
                    ->label('Model count')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                VehicleMakeResource::makeDeleteAction(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No vehicle catalogue yet')
            ->emptyStateDescription('Add makes and models here so vehicle-specific products can reuse the shared catalogue.');
    }
}
