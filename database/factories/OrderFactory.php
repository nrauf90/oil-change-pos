<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'sale_id' => null,
            'status' => OrderStatus::Draft,
            'label' => null,
            'customer_name' => $this->faker->name(),
            'phone' => '03'.$this->faker->numerify('########'),
            'vehicle_model' => $this->faker->randomElement(['Toyota Corolla 2018', 'Honda Civic 2021', 'Suzuki Alto 2020']),
            'vehicle_plate' => strtoupper($this->faker->bothify('??#-####')),
            'mileage' => $this->faker->numberBetween(5_000, 250_000),
            'next_checkup_mileage' => null,
            'labor_charge' => 0,
            'misc_charge' => 0,
            'notes' => null,
            'version' => 1,
        ];
    }

    /** A bill with nothing typed on it yet — the counter saved it the moment the car arrived. */
    public function bare(): static
    {
        return $this->state(fn () => [
            'customer_name' => null,
            'phone' => null,
            'vehicle_model' => null,
            'vehicle_plate' => null,
            'mileage' => null,
        ]);
    }

    public function label(string $label): static
    {
        return $this->state(fn () => ['label' => $label]);
    }
}
