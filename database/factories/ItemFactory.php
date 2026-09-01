<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ItemType;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Item> */
class ItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => ucwords($this->faker->unique()->words(3, true)),
            'type' => ItemType::Product,
            'is_universal' => false,
            'unit_cost' => $this->faker->randomFloat(2, 500, 9000),
            'stock_level' => null,
            'low_stock_alert' => null,
            'is_active' => true,
        ];
    }

    public function repair(): static
    {
        return $this->state(fn () => ['type' => ItemType::Repair]);
    }

    public function withoutUnitCost(): static
    {
        return $this->state(fn () => ['unit_cost' => null]);
    }

    /** A stock-tracked product, optionally with a low-stock threshold. */
    public function tracked(int $stockLevel, ?int $lowStockAlert = null): static
    {
        return $this->state(fn () => [
            'type' => ItemType::Product,
            'stock_level' => $stockLevel,
            'low_stock_alert' => $lowStockAlert,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
