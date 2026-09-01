<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemVehicleCompatibility;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VehicleProductCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_and_catalogue_seed_reference_vehicles_without_overwriting_existing_compatibility(): void
    {
        $toyota = VehicleMake::factory()->create(['name' => 'Toyota']);
        $corolla = VehicleModel::factory()->for($toyota)->create(['name' => 'Corolla']);
        $legacyProduct = Item::factory()->create([
            'name' => 'Oil Filter — Toyota Corolla',
            'is_universal' => false,
        ]);
        ItemVehicleCompatibility::factory()->for($legacyProduct)->for($corolla)->create([
            'year_from' => 2009,
            'year_to' => 2013,
        ]);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame([
            'Daihatsu' => ['Cuore', 'Hijet', 'Mira'],
            'FAW' => ['V2', 'XPV'],
            'Honda' => ['BR-V', 'City', 'Civic', 'HR-V'],
            'Hyundai' => ['Elantra', 'Tucson'],
            'Kia' => ['Picanto', 'Sportage'],
            'Mitsubishi' => ['Lancer', 'Pajero'],
            'Nissan' => ['Dayz', 'Sunny'],
            'Suzuki' => ['Alto', 'Bolan', 'Cultus', 'Mehran', 'Ravi', 'Swift', 'Wagon R'],
            'Toyota' => ['Aqua', 'Corolla', 'Hilux', 'Prado', 'Vitz', 'Yaris'],
        ], VehicleMake::query()
            ->with('vehicleModels')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (VehicleMake $make): array => [
                $make->name => [$make->vehicleModels->pluck('name')->sort()->values()->all()],
            ])
            ->map(fn (array $modelNames): array => $modelNames[0])
            ->all());
        $this->assertSame([[2009, 2013]], $legacyProduct->fresh()->vehicleCompatibilities
            ->map(fn (ItemVehicleCompatibility $compatibility): array => [$compatibility->year_from, $compatibility->year_to])
            ->all());
    }

    public function test_a_product_can_have_several_model_and_year_compatibilities(): void
    {
        $product = Item::factory()->create(['is_universal' => false]);
        $toyota = VehicleMake::factory()->create(['name' => 'Toyota']);
        $corolla = VehicleModel::factory()->for($toyota)->create(['name' => 'Corolla']);
        $yaris = VehicleModel::factory()->for($toyota)->create(['name' => 'Yaris']);

        ItemVehicleCompatibility::factory()->for($product)->for($corolla)->create(['year_from' => 2009, 'year_to' => 2013]);
        ItemVehicleCompatibility::factory()->for($product)->for($yaris)->create(['year_from' => 2011, 'year_to' => null]);

        $this->assertCount(2, $product->vehicleCompatibilities);
        $this->assertSame(['Corolla', 'Yaris'], $product->vehicleCompatibilities
            ->map(fn (ItemVehicleCompatibility $compatibility): string => $compatibility->vehicleModel->name)
            ->sort()->values()->all());
    }

    public function test_vehicle_model_names_are_unique_within_a_make(): void
    {
        $toyota = VehicleMake::factory()->create();
        VehicleModel::factory()->for($toyota)->create(['name' => 'Corolla']);

        $this->expectException(ValidationException::class);

        VehicleModel::factory()->for($toyota)->create(['name' => 'Corolla']);
    }

    public function test_compatibility_rejects_an_inverted_year_range(): void
    {
        $this->expectException(ValidationException::class);

        ItemVehicleCompatibility::factory()->create(['year_from' => 2020, 'year_to' => 2015]);
    }

    public function test_duplicate_open_ended_compatibility_is_rejected(): void
    {
        $product = Item::factory()->create(['is_universal' => false]);
        $model = VehicleModel::factory()->create();
        ItemVehicleCompatibility::factory()->for($product)->for($model)->create();

        $this->expectException(ValidationException::class);

        ItemVehicleCompatibility::factory()->for($product)->for($model)->create();
    }

    public function test_compatibility_rejects_years_before_2000(): void
    {
        $this->expectException(ValidationException::class);

        ItemVehicleCompatibility::factory()->create(['year_from' => 1999]);
    }

    public function test_compatibility_rejects_years_after_2026(): void
    {
        $this->expectException(ValidationException::class);

        ItemVehicleCompatibility::factory()->create(['year_to' => 2027]);
    }

    public function test_repairs_cannot_receive_vehicle_compatibilities(): void
    {
        $repair = Item::factory()->repair()->create();

        $this->expectException(ValidationException::class);

        ItemVehicleCompatibility::factory()->for($repair)->create();
    }

    public function test_universal_products_cannot_receive_vehicle_compatibilities(): void
    {
        $product = Item::factory()->create(['is_universal' => true]);

        $this->expectException(ValidationException::class);

        ItemVehicleCompatibility::factory()->for($product)->create();
    }

    public function test_vehicle_names_are_trimmed_and_case_insensitively_unique(): void
    {
        $toyota = VehicleMake::factory()->create(['name' => ' Toyota ']);
        $corolla = VehicleModel::factory()->for($toyota)->create(['name' => ' Corolla ']);

        $this->assertSame('Toyota', $toyota->name);
        $this->assertSame('Corolla', $corolla->name);

        $this->expectException(ValidationException::class);

        VehicleMake::factory()->create(['name' => 'TOYOTA']);
    }

    public function test_vehicle_filter_includes_universal_and_matching_specific_products(): void
    {
        $toyota = VehicleMake::factory()->create();
        $corolla = VehicleModel::factory()->for($toyota)->create();
        $universal = Item::factory()->create(['is_universal' => true]);
        $matching = Item::factory()->create(['is_universal' => false]);
        ItemVehicleCompatibility::factory()->for($matching)->for($corolla)->create();
        Item::factory()->create(['is_universal' => false]);

        $ids = Item::query()->compatibleWith($toyota->id, $corolla->id, 2015)->pluck('id');

        $this->assertEqualsCanonicalizing([$universal->id, $matching->id], $ids->all());
    }

    public function test_vehicle_filter_honours_open_and_inclusive_year_bounds(): void
    {
        $corolla = VehicleModel::factory()->create();
        $before = Item::factory()->create(['is_universal' => false]);
        $bounded = Item::factory()->create(['is_universal' => false]);
        $after = Item::factory()->create(['is_universal' => false]);
        ItemVehicleCompatibility::factory()->for($before)->for($corolla)->create(['year_from' => null, 'year_to' => 2008]);
        ItemVehicleCompatibility::factory()->for($bounded)->for($corolla)->create(['year_from' => 2009, 'year_to' => 2013]);
        ItemVehicleCompatibility::factory()->for($after)->for($corolla)->create(['year_from' => 2014, 'year_to' => null]);

        $this->assertSame([$before->id], Item::query()->compatibleWith(null, $corolla->id, 2008)->pluck('id')->all());
        $this->assertSame([$bounded->id], Item::query()->compatibleWith(null, $corolla->id, 2009)->pluck('id')->all());
        $this->assertSame([$bounded->id], Item::query()->compatibleWith(null, $corolla->id, 2013)->pluck('id')->all());
        $this->assertSame([$after->id], Item::query()->compatibleWith(null, $corolla->id, 2014)->pluck('id')->all());
    }

    public function test_vehicle_filter_excludes_non_matching_products(): void
    {
        $toyota = VehicleMake::factory()->create();
        $honda = VehicleMake::factory()->create();
        $corolla = VehicleModel::factory()->for($toyota)->create();
        $civic = VehicleModel::factory()->for($honda)->create();
        $toyotaPart = Item::factory()->create(['is_universal' => false]);
        $hondaPart = Item::factory()->create(['is_universal' => false]);
        ItemVehicleCompatibility::factory()->for($toyotaPart)->for($corolla)->create();
        ItemVehicleCompatibility::factory()->for($hondaPart)->for($civic)->create();

        $this->assertSame([$toyotaPart->id], Item::query()->compatibleWith($toyota->id, null, null)->pluck('id')->all());
    }

    public function test_empty_vehicle_filter_leaves_the_query_unchanged(): void
    {
        $specific = Item::factory()->create(['is_universal' => false]);

        $this->assertSame([$specific->id], Item::query()->compatibleWith(null, null, null)->pluck('id')->all());
    }
}
