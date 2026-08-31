<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SaleLineType;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SaleItem> */
class SaleItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'item_id' => null,
            'item_name' => ucwords($this->faker->words(2, true)),
            'type' => SaleLineType::Product,
            'manually_charged_price' => $this->faker->randomFloat(2, 100, 9000),
        ];
    }

    public function custom(): static
    {
        return $this->state(fn () => ['type' => SaleLineType::Custom, 'item_id' => null]);
    }

    public function repair(): static
    {
        return $this->state(fn () => ['type' => SaleLineType::Repair]);
    }
}
