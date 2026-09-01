<?php

namespace Database\Factories;

use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleModel>
 */
class VehicleModelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_make_id' => VehicleMake::factory(),
            'name' => ucfirst($this->faker->unique()->word()),
        ];
    }
}
