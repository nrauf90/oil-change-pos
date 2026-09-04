<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExpenseReceipt> */
class ExpenseReceiptFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->word().'.jpg';

        return [
            'expense_id' => Expense::factory(),
            'user_id' => User::factory(),
            'path' => 'tenants/1/expense-receipts/'.$this->faker->uuid().'.jpg',
            'original_name' => $name,
            'mime_type' => 'image/jpeg',
            'size_in_bytes' => $this->faker->numberBetween(1024, 512000),
        ];
    }

    public function pdf(): static
    {
        return $this->state(fn () => [
            'path' => 'tenants/1/expense-receipts/'.$this->faker->uuid().'.pdf',
            'original_name' => 'invoice.pdf',
            'mime_type' => 'application/pdf',
        ]);
    }
}
