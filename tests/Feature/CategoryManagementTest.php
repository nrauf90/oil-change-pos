<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
}
