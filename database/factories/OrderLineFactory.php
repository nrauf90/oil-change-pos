<?php

namespace Database\Factories;

use App\Enums\SaleLineType;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderLine> */
class OrderLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'item_id' => null,
            'item_name' => $this->faker->words(2, true),
            'type' => SaleLineType::Custom,
            'quantity' => 1,
            'dispensed_quantity' => null,
            'manually_charged_price' => $this->faker->randomFloat(2, 100, 5000),
        ];
    }

    public function price(string|float|int $price): static
    {
        return $this->state(fn () => ['manually_charged_price' => $price]);
    }
}
