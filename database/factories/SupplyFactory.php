<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\Supply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supply>
 */
class SupplyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'received_at' => fake()->dateTimeBetween('-6 months'),
            'reference_number' => strtoupper(fake()->bothify('BILL-####')),
            'items_received' => fake()->sentence(),
            'total_amount' => fake()->randomFloat(2, 1000, 100000),
            'bill_image_path' => null,
            'notes' => null,
        ];
    }
}
