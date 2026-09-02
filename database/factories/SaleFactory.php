<?php

namespace Database\Factories;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sale> */
class SaleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_name' => $this->faker->name(),
            'phone' => '03'.$this->faker->numerify('########'),
            'vehicle_model' => $this->faker->randomElement(['Toyota Corolla 2018', 'Honda Civic 2021', 'Suzuki Alto 2020']),
            'vehicle_plate' => strtoupper($this->faker->bothify('??#-####')),
            'mileage' => $this->faker->numberBetween(5_000, 250_000),
            'next_checkup_mileage' => null,
            'labor_charge' => 0,
            'misc_charge' => 0,
            'total_amount' => 0,
            'notes' => null,
        ];
    }
}
