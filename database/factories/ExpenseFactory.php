<?php

namespace Database\Factories;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
class ExpenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => $this->faker->randomElement(ExpenseCategory::cases()),
            'payment_method' => null,
            'amount' => $this->faker->randomFloat(2, 50, 5000),
            'description' => $this->faker->sentence(4),
            'spent_at' => now(),
        ];
    }

    public function category(ExpenseCategory $category): static
    {
        return $this->state(fn () => ['category' => $category]);
    }

    public function amount(string|float|int $amount): static
    {
        return $this->state(fn () => ['amount' => $amount]);
    }

    public function spentAt(mixed $when): static
    {
        return $this->state(fn () => ['spent_at' => $when]);
    }
}
