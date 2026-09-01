<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemVehicleCompatibility;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemVehicleCompatibility>
 */
class ItemVehicleCompatibilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'vehicle_model_id' => VehicleModel::factory(),
            'year_from' => null,
            'year_to' => null,
        ];
    }
}
