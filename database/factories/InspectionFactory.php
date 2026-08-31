<?php

namespace Database\Factories;

use App\Enums\InspectionPoint;
use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inspection> */
class InspectionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sale_id' => null,
            'inspected_by' => User::factory()->technician(),
            'customer_name' => $this->faker->name(),
            'phone' => '03'.$this->faker->numerify('########'),
            'vehicle_plate' => strtoupper($this->faker->bothify('??#-####')),
            'vehicle_model' => $this->faker->randomElement(['Toyota Corolla 2018', 'Honda Civic 2021', 'Suzuki Alto 2020']),
            'mileage' => $this->faker->numberBetween(5_000, 250_000),
            'notes' => null,
            'inspected_at' => now(),
        ];
    }

    /** A full walk-around with every check-point marked OK. */
    public function allClear(): static
    {
        return $this->afterCreating(function (Inspection $inspection): void {
            $inspection->syncPoints(
                collect(InspectionPoint::cases())
                    ->mapWithKeys(fn (InspectionPoint $point) => [
                        $point->value => ['status' => InspectionStatus::Ok->value],
                    ])
                    ->all()
            );
        });
    }

    /** A walk-around that found something the customer needs to hear about. */
    public function withConcerns(): static
    {
        return $this->afterCreating(function (Inspection $inspection): void {
            $inspection->syncPoints([
                InspectionPoint::EngineOil->value => ['status' => InspectionStatus::Ok->value],
                InspectionPoint::BrakePads->value => ['status' => InspectionStatus::NeedsAttention->value, 'note' => 'Fronts down to 3mm'],
                InspectionPoint::Battery->value => ['status' => InspectionStatus::Urgent->value, 'note' => 'Will not hold a charge'],
            ]);
        });
    }
}
