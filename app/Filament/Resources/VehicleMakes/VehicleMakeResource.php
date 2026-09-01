<?php

namespace App\Filament\Resources\VehicleMakes;

use App\Enums\Permission;
use App\Filament\Resources\VehicleMakes\Pages\CreateVehicleMake;
use App\Filament\Resources\VehicleMakes\Pages\EditVehicleMake;
use App\Filament\Resources\VehicleMakes\Pages\ListVehicleMakes;
use App\Filament\Resources\VehicleMakes\Schemas\VehicleMakeForm;
use App\Filament\Resources\VehicleMakes\Tables\VehicleMakesTable;
use App\Models\Item;
use App\Models\VehicleMake;
use App\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Repeater;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class VehicleMakeResource extends Resource
{
    protected static ?string $model = VehicleMake::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Vehicle catalogue';

    protected static ?string $modelLabel = 'vehicle make';

    protected static ?string $pluralModelLabel = 'vehicle catalogue';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return VehicleMakeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VehicleMakesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicleMakes::route('/'),
            'create' => CreateVehicleMake::route('/create'),
            'edit' => EditVehicleMake::route('/{record}/edit'),
        ];
    }

    private static function moduleEnabled(): bool
    {
        return app(ModuleRegistry::class)->enabled('inventory');
    }

    public static function canViewAny(): bool
    {
        return self::moduleEnabled()
            && (auth()->user()?->can(Permission::ViewAnyItem->value) ?? false);
    }

    public static function canCreate(): bool
    {
        return self::moduleEnabled()
            && (auth()->user()?->can(Permission::CreateItem->value) ?? false);
    }

    public static function canEdit(Model $record): bool
    {
        return self::moduleEnabled()
            && (auth()->user()?->can(Permission::UpdateItem->value) ?? false);
    }

    public static function canDelete(Model $record): bool
    {
        return self::moduleEnabled()
            && (auth()->user()?->can(Permission::DeleteItem->value) ?? false);
    }

    public static function makeDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (VehicleMake $record): string => "Delete {$record->name}?")
            ->modalDescription(fn (VehicleMake $record): HtmlString => self::deleteWarning(
                self::affectedProductNamesForMake($record),
            ))
            ->successNotificationTitle('Vehicle make deleted')
            ->action(function (DeleteAction $action, VehicleMake $record): void {
                $record->delete();
                $action->success();
            });
    }

    public static function configureVehicleModelDeleteAction(Action $action): Action
    {
        return $action
            ->requiresConfirmation()
            ->modalHeading('Delete vehicle model?')
            ->modalDescription(function (array $arguments, Repeater $component): HtmlString {
                $row = $component->getRawState()[$arguments['item']] ?? [];
                $vehicleModelId = (int) ($row['id'] ?? 0);

                return self::deleteWarning(self::affectedProductNamesForModel($vehicleModelId));
            })
            ->modalSubmitActionLabel('Delete model');
    }

    /**
     * @return array<int, string>
     */
    public static function affectedProductNamesForMake(VehicleMake $vehicleMake): array
    {
        return Item::query()
            ->whereHas('vehicleCompatibilities.vehicleModel', fn ($query) => $query->where('vehicle_make_id', $vehicleMake->getKey()))
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function affectedProductNamesForModel(int $vehicleModelId): array
    {
        if ($vehicleModelId === 0) {
            return [];
        }

        return Item::query()
            ->whereHas('vehicleCompatibilities', fn ($query) => $query->where('vehicle_model_id', $vehicleModelId))
            ->orderBy('name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    private static function deleteWarning(array $productNames): HtmlString
    {
        if ($productNames === []) {
            return new HtmlString(
                '<p>This will remove the selected vehicle record.</p><p>No product compatibility assignments will be affected.</p>'
            );
        }

        $products = implode('', array_map(
            fn (string $name): string => '<li>'.e($name).'</li>',
            $productNames,
        ));

        return new HtmlString(
            '<p>Compatibility assignments for these products will be removed.</p>'.
            '<ul class="list-disc ps-5">'.$products.'</ul>'.
            '<p>The products themselves will stay in inventory.</p>',
        );
    }
}
