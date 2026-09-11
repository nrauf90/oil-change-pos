<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemVehicleCompatibility;
use App\Models\User;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
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

    public function test_a_plain_request_to_the_inventory_index_returns_the_full_page(): void
    {
        Item::factory()->create(['name' => 'Brake Fluid']);

        $this->get(route('items.index'))
            ->assertOk()
            ->assertSee('<h1', false)
            ->assertSee('Inventory')
            ->assertSee('Brake Fluid');
    }

    public function test_a_live_search_request_returns_only_the_results_fragment(): void
    {
        Item::factory()->create(['name' => 'Brake Fluid']);

        $response = $this->get(route('items.index'), ['X-Inventory-Search' => '1']);

        $response->assertOk()
            ->assertSee('Brake Fluid')
            ->assertDontSee('<h1', false);
    }

    public function test_a_live_search_request_filters_by_the_search_term(): void
    {
        Item::factory()->create(['name' => 'Brake Fluid']);
        Item::factory()->create(['name' => 'Engine Oil']);

        $response = $this->get(route('items.index', ['q' => 'Brake']), ['X-Inventory-Search' => '1']);

        $response->assertOk()
            ->assertSee('Brake Fluid')
            ->assertDontSee('Engine Oil');
    }

    public function test_creating_a_non_universal_product_persists_its_vehicle_compatibility_rows(): void
    {
        $make = VehicleMake::factory()->create(['name' => 'Toyota']);
        $model = VehicleModel::factory()->for($make)->create(['name' => 'Corolla']);

        $this->post(route('items.store'), [
            'name' => 'Corolla Brake Pad',
            'type' => 'product',
            'is_universal' => '0',
            'vehicle_compatibilities' => [
                ['vehicle_model_id' => $model->id, 'year_from' => '2010', 'year_to' => '2015'],
            ],
        ])->assertRedirect(route('items.index'));

        $item = Item::sole();
        $this->assertFalse($item->is_universal);
        $this->assertDatabaseHas('item_vehicle_compatibilities', [
            'item_id' => $item->id,
            'vehicle_model_id' => $model->id,
            'year_from' => 2010,
            'year_to' => 2015,
        ], 'tenant');
    }

    public function test_switching_an_item_to_universal_on_update_clears_existing_compatibility_rows(): void
    {
        $make = VehicleMake::factory()->create();
        $model = VehicleModel::factory()->for($make)->create();
        $item = Item::factory()->create(['is_universal' => false]);
        ItemVehicleCompatibility::factory()->for($item)->for($model)->create();

        $this->put(route('items.update', $item), [
            'name' => $item->name,
            'type' => 'product',
            'is_universal' => '1',
        ])->assertRedirect(route('items.index'));

        $this->assertDatabaseCount('item_vehicle_compatibilities', 0, 'tenant');
        $this->assertTrue($item->refresh()->is_universal);
    }

    public function test_submitting_an_invalid_vehicle_model_id_is_rejected(): void
    {
        $this->post(route('items.store'), [
            'name' => 'Mystery Compatible Part',
            'type' => 'product',
            'is_universal' => '0',
            'vehicle_compatibilities' => [
                ['vehicle_model_id' => 999999, 'year_from' => '2010', 'year_to' => '2015'],
            ],
        ])->assertSessionHasErrors('vehicle_compatibilities.0.vehicle_model_id');

        $this->assertDatabaseCount('items', 0, 'tenant');
    }

    public function test_a_duplicate_compatibility_row_within_one_submission_surfaces_a_validation_error_not_a_500(): void
    {
        $make = VehicleMake::factory()->create();
        $model = VehicleModel::factory()->for($make)->create();

        // Both rows are individually valid, so Laravel's form-request
        // validation passes them through — it is `ItemVehicleCompatibility`'s
        // own `booted()` duplicate guard that has to catch the repeat, and
        // its `ValidationException` has to redirect back with errors rather
        // than bubble up as an unhandled 500.
        $response = $this->from(route('items.create'))->post(route('items.store'), [
            'name' => 'Duplicate Row Part',
            'type' => 'product',
            'is_universal' => '0',
            'vehicle_compatibilities' => [
                ['vehicle_model_id' => $model->id, 'year_from' => '2010', 'year_to' => '2015'],
                ['vehicle_model_id' => $model->id, 'year_from' => '2010', 'year_to' => '2015'],
            ],
        ]);

        $response->assertRedirect(route('items.create'))
            ->assertSessionHasErrors('vehicle_model_id');
        $this->assertDatabaseCount('item_vehicle_compatibilities', 1, 'tenant');
    }
}
