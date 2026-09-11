<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Filament\Resources\Categories\Tables\CategoriesTable;
use App\Models\Category;
use App\Models\Item;
use App\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $modelLabel = 'category';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }

    private static function moduleEnabled(): bool
    {
        return app(ModuleRegistry::class)->enabled('inventory');
    }

    /**
     * Overriding the `*AuthorizationResponse()` methods rather than the
     * `can*()` wrappers: Filament's action authorization calls the former
     * directly, so gating only the wrappers leaves the buttons live.
     */
    private static function gate(bool $granted): Response
    {
        return $granted ? Response::allow() : Response::deny();
    }

    private static function adminOnly(): bool
    {
        return self::moduleEnabled()
            && (auth()->user()?->isAdmin() ?? false);
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        return self::gate(self::adminOnly());
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return self::gate(self::adminOnly());
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return self::gate(self::adminOnly());
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return self::gate(self::adminOnly());
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return self::gate(self::adminOnly());
    }

    public static function makeDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (Category $record): string => "Delete {$record->name}?")
            ->modalDescription(fn (Category $record): HtmlString => self::deleteWarning(
                self::affectedItemNames($record),
            ))
            ->successNotificationTitle('Category deleted')
            ->action(function (DeleteAction $action, Category $record): void {
                $record->delete();
                $action->success();
            });
    }

    /** @return array<int, string> */
    public static function affectedItemNames(Category $category): array
    {
        return Item::query()
            ->where('category_id', $category->getKey())
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    private static function deleteWarning(array $itemNames): HtmlString
    {
        if ($itemNames === []) {
            return new HtmlString(
                '<p>This will remove the selected category.</p><p>No items are assigned to it.</p>'
            );
        }

        $items = implode('', array_map(
            fn (string $name): string => '<li>'.e($name).'</li>',
            $itemNames,
        ));

        return new HtmlString(
            '<p>These items will lose their category:</p>'.
            '<ul class="list-disc ps-5">'.$items.'</ul>'.
            '<p>The items themselves will stay in inventory.</p>',
        );
    }
}
