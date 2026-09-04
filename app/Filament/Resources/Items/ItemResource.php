<?php

namespace App\Filament\Resources\Items;

use App\Enums\Permission;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Filament\Resources\Items\Schemas\ItemForm;
use App\Filament\Resources\Items\Tables\ItemsTable;
use App\Models\Item;
use App\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class ItemResource extends Resource
{
    protected static ?string $model = Item::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Inventory';

    protected static ?string $modelLabel = 'item';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return ItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ItemsTable::configure($table);
    }

    /** Flags how many items need reordering, right in the sidebar. */
    public static function getNavigationBadge(): ?string
    {
        $low = Item::query()->lowStock()->count();

        return $low > 0 ? (string) $low : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /* -------- Authorization: module gate, then one permission per action -------- */

    private static function moduleEnabled(): bool
    {
        return app(ModuleRegistry::class)->enabled('inventory');
    }

    /**
     * Filament resolves each action's authorization through the
     * `*AuthorizationResponse()` methods; `canViewAny()`, `canCreate()`,
     * `canEdit()` and `canDelete()` are only wrappers over them. Gating the
     * response methods covers the table row buttons and page headers as well
     * as the navigation — overriding the wrappers alone leaves the buttons
     * live, because `Resources\Pages\Page::getDefaultActionAuthorizationResponse()`
     * never calls them.
     */
    private static function gate(bool $granted): Response
    {
        return $granted ? Response::allow() : Response::deny();
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return self::gate(self::moduleEnabled()
            && (auth()->user()?->can(Permission::ViewAnyItem->value) ?? false));
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return self::gate(self::moduleEnabled()
            && (auth()->user()?->can(Permission::CreateItem->value) ?? false));
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return self::gate(self::moduleEnabled()
            && (auth()->user()?->can(Permission::UpdateItem->value) ?? false));
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return self::gate(self::deleteGranted());
    }

    /** Bulk delete is not registered today; gate it so adding one cannot reopen this. */
    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return self::gate(self::deleteGranted());
    }

    private static function deleteGranted(): bool
    {
        return self::moduleEnabled()
            && (auth()->user()?->can(Permission::DeleteItem->value) ?? false);
    }

    /** Booking in a delivery: Admin and Manager only, never a technician. */
    public static function canManageStock(): bool
    {
        return self::moduleEnabled()
            && (auth()->user()?->can(Permission::ManageStock->value) ?? false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListItems::route('/'),
            'create' => CreateItem::route('/create'),
            'edit' => EditItem::route('/{record}/edit'),
        ];
    }
}
