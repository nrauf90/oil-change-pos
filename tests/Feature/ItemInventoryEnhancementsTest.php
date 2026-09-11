<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ItemInventoryEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_an_item_can_be_created_with_a_selling_price_and_category(): void
    {
        $category = Category::factory()->create(['name' => 'Filters']);

        $this->post(route('items.store'), [
            'name' => 'Oil Filter',
            'type' => 'product',
            'selling_price' => '350.75',
            'category_id' => $category->id,
        ])->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'name' => 'Oil Filter',
            'selling_price' => '350.75',
            'category_id' => $category->id,
        ], 'tenant');
    }

    public function test_an_item_can_be_updated_with_a_selling_price_and_category_and_round_trips_through_the_edit_form(): void
    {
        $category = Category::factory()->create(['name' => 'Lubricants']);
        $item = Item::factory()->create(['selling_price' => null, 'category_id' => null]);

        $this->put(route('items.update', $item), [
            'name' => $item->name,
            'type' => $item->type->value,
            'selling_price' => '499.99',
            'category_id' => $category->id,
        ])->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'selling_price' => '499.99',
            'category_id' => $category->id,
        ], 'tenant');

        $this->get(route('items.edit', $item))
            ->assertOk()
            ->assertSee('499.99')
            ->assertSee('Lubricants');
    }

    public function test_a_blank_category_selection_clears_the_category(): void
    {
        $category = Category::factory()->create();
        $item = Item::factory()->create(['category_id' => $category->id]);

        $this->put(route('items.update', $item), [
            'name' => $item->name,
            'type' => $item->type->value,
            'category_id' => '',
        ])->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', ['id' => $item->id, 'category_id' => null], 'tenant');
    }

    public function test_an_unknown_category_id_is_rejected(): void
    {
        $this->post(route('items.store'), [
            'name' => 'Mystery Part',
            'type' => 'product',
            'category_id' => 99999,
        ])->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('items', 0, 'tenant');
    }

    public function test_the_inventory_index_shows_selling_price_and_category_but_never_unit_cost(): void
    {
        $category = Category::factory()->create(['name' => 'Brake Parts']);
        Item::factory()->create([
            'name' => 'Brake Pad',
            'selling_price' => '850.00',
            'unit_cost' => '600.00',
            'category_id' => $category->id,
        ]);

        $this->get(route('items.index'))
            ->assertOk()
            ->assertSee('Selling price')
            ->assertSee('850.00')
            ->assertSee('Brake Parts')
            ->assertDontSee('Unit cost')
            ->assertDontSee('600.00');
    }

    public function test_a_manager_sees_selling_price_but_never_unit_cost_on_the_inventory_index(): void
    {
        Item::factory()->create(['name' => 'Spark Plug', 'selling_price' => '120.00', 'unit_cost' => '80.00']);

        $this->actingAs(User::factory()->manager()->create());

        $this->get(route('items.index'))
            ->assertOk()
            ->assertSee('Selling price')
            ->assertSee('120.00')
            ->assertDontSee('Unit cost')
            ->assertDontSee('80.00');
    }

    public function test_the_inventory_index_can_be_filtered_by_category(): void
    {
        $filters = Category::factory()->create(['name' => 'Filters']);
        $oils = Category::factory()->create(['name' => 'Oils']);
        Item::factory()->create(['name' => 'Air Filter', 'category_id' => $filters->id]);
        Item::factory()->create(['name' => 'Engine Oil', 'category_id' => $oils->id]);

        $this->get(route('items.index', ['category' => $filters->id]))
            ->assertOk()
            ->assertSee('Air Filter')
            ->assertDontSee('Engine Oil');
    }

    public function test_uploading_a_valid_image_on_create_stores_it_and_the_show_route_streams_it_back(): void
    {
        $this->post(route('items.store'), [
            'name' => 'Timing Belt',
            'type' => 'product',
            'image' => UploadedFile::fake()->image('belt.jpg'),
        ])->assertRedirect(route('items.index'));

        $item = Item::sole();
        $this->assertNotNull($item->image_path);
        Storage::disk('local')->assertExists($item->image_path);

        $this->get(route('items.image', $item))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_uploading_a_valid_image_on_update_stores_it(): void
    {
        $item = Item::factory()->create();

        $this->put(route('items.update', $item), [
            'name' => $item->name,
            'type' => $item->type->value,
            'image' => UploadedFile::fake()->image('gasket.png'),
        ])->assertRedirect(route('items.index'));

        $item->refresh();
        $this->assertNotNull($item->image_path);
        Storage::disk('local')->assertExists($item->image_path);
    }

    public function test_an_invalid_file_type_is_rejected(): void
    {
        $this->post(route('items.store'), [
            'name' => 'Bad Upload',
            'type' => 'product',
            'image' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseCount('items', 0, 'tenant');
    }

    public function test_checking_remove_image_on_update_clears_it_and_the_route_then_404s(): void
    {
        $item = Item::factory()->create();
        $this->put(route('items.update', $item), [
            'name' => $item->name,
            'type' => $item->type->value,
            'image' => UploadedFile::fake()->image('old.jpg'),
        ])->assertRedirect(route('items.index'));

        $item->refresh();
        $previousPath = $item->image_path;

        $this->put(route('items.update', $item), [
            'name' => $item->name,
            'type' => $item->type->value,
            'remove_image' => '1',
        ])->assertRedirect(route('items.index'));

        $item->refresh();
        $this->assertNull($item->image_path);
        Storage::disk('local')->assertMissing($previousPath);
        $this->get(route('items.image', $item))->assertNotFound();
    }

    public function test_deleting_an_item_removes_its_stored_file(): void
    {
        $item = Item::factory()->create();
        $this->put(route('items.update', $item), [
            'name' => $item->name,
            'type' => $item->type->value,
            'image' => UploadedFile::fake()->image('doomed.jpg'),
        ])->assertRedirect(route('items.index'));

        $item->refresh();
        $path = $item->image_path;

        $this->delete(route('items.destroy', $item))->assertRedirect(route('items.index'));

        Storage::disk('local')->assertMissing($path);
    }
}
