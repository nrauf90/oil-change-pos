<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Items\Pages\CreateItem;
use App\Filament\Resources\Items\Pages\EditItem;
use App\Filament\Resources\Items\Pages\ListItems;
use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_category_can_be_created(): void
    {
        $category = Category::factory()->create(['name' => 'Filters']);

        $this->assertDatabaseHas('categories', ['id' => $category->getKey(), 'name' => 'Filters'], 'tenant');
    }

    public function test_a_duplicate_category_name_is_rejected_regardless_of_case(): void
    {
        Category::factory()->create(['name' => 'Filters']);

        $this->expectException(ValidationException::class);

        Category::factory()->create(['name' => 'fILTERS']);
    }

    public function test_an_item_can_be_created_with_a_selling_price_and_category(): void
    {
        $category = Category::factory()->create(['name' => 'Lubricants']);

        $item = Item::factory()->create([
            'selling_price' => '1250.50',
            'category_id' => $category->getKey(),
        ]);

        $item->refresh();

        $this->assertSame('1250.50', $item->selling_price);
        $this->assertTrue($category->is($item->category));
        $this->assertDatabaseHas('items', [
            'id' => $item->getKey(),
            'selling_price' => '1250.50',
            'category_id' => $category->getKey(),
        ], 'tenant');
    }

    public function test_an_admin_can_create_a_category_from_the_admin_panel(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateCategory::class)
            ->fillForm(['name' => 'Filters'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('categories', ['name' => 'Filters'], 'tenant');
    }

    public function test_an_admin_can_edit_a_category_from_the_admin_panel(): void
    {
        $category = Category::factory()->create(['name' => 'Filters']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(EditCategory::class, ['record' => $category->getKey()])
            ->fillForm(['name' => 'Oil Filters'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Oil Filters', $category->fresh()->name);
    }

    public function test_an_admin_can_delete_a_category_from_the_admin_panel(): void
    {
        $category = Category::factory()->create(['name' => 'Filters']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListCategories::class)
            ->callTableAction('delete', $category)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertDatabaseMissing('categories', ['id' => $category->getKey()], 'tenant');
    }

    public function test_a_manager_cannot_access_the_category_resource(): void
    {
        $manager = User::factory()->manager()->create();
        $category = Category::factory()->create();

        $this->actingAs($manager);

        $this->assertFalse(CategoryResource::canViewAny());
        $this->assertFalse(CategoryResource::canCreate());
        $this->assertFalse(CategoryResource::canEdit($category));
    }

    public function test_the_item_filament_form_persists_a_category_on_create_and_edit(): void
    {
        $category = Category::factory()->create(['name' => 'Lubricants']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateItem::class)
            ->fillForm([
                'name' => 'Synthetic Oil',
                'type' => 'product',
                'is_universal' => true,
                'category_id' => $category->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = Item::query()->where('name', 'Synthetic Oil')->sole();
        $this->assertSame($category->getKey(), $item->category_id);

        $otherCategory = Category::factory()->create(['name' => 'Filters']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(EditItem::class, ['record' => $item->getKey()])
            ->fillForm(['category_id' => $otherCategory->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($otherCategory->getKey(), $item->fresh()->category_id);
    }

    public function test_the_items_table_shows_the_category_column_and_filters_by_category(): void
    {
        $filters = Category::factory()->create(['name' => 'Filters']);
        $oils = Category::factory()->create(['name' => 'Oils']);
        $airFilter = Item::factory()->create(['name' => 'Air Filter', 'category_id' => $filters->getKey()]);
        $engineOil = Item::factory()->create(['name' => 'Engine Oil', 'category_id' => $oils->getKey()]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListItems::class)
            ->assertCanSeeTableRecords([$airFilter, $engineOil])
            ->filterTable('category_id', $filters->getKey())
            ->assertCanSeeTableRecords([$airFilter])
            ->assertCanNotSeeTableRecords([$engineOil]);
    }
}
