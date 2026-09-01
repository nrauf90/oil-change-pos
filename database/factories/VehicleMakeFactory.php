<?php

namespace Database\Factories;

use App\Models\VehicleMake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleMake>
 */
class VehicleMakeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->word()),
        ];
    }
}
