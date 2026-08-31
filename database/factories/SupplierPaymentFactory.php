<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\SupplierPayment;
use App\Models\Supply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPayment>
 */
class SupplierPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supply_id' => Supply::factory(),
            'user_id' => User::factory(),
            'amount' => fake()->randomFloat(2, 100, 1000),
            'method' => fake()->randomElement(PaymentMethod::cases()),
            'paid_at' => fake()->dateTimeBetween('-3 months'),
            'reference_number' => null,
            'receipt_image_path' => null,
            'notes' => null,
        ];
    }
}
