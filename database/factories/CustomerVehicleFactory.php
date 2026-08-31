<?php

namespace Database\Factories;

use App\Models\CustomerVehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerVehicle>
 */
class CustomerVehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_name' => fake()->name(),
            'phone' => '03'.fake()->numerify('########'),
            'vehicle_model' => fake()->randomElement(['Toyota Corolla 2018', 'Honda Civic 2021', 'Suzuki Alto 2020']),
            'vehicle_plate' => strtoupper(fake()->unique()->bothify('??#-####')),
            'mileage' => fake()->numberBetween(5_000, 250_000),
        ];
    }
}
