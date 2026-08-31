<?php

namespace Database\Factories;

use App\Enums\InspectionPoint;
use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\InspectionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InspectionItem> */
class InspectionItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $point = $this->faker->randomElement(InspectionPoint::cases());

        return [
            'inspection_id' => Inspection::factory(),
            'point' => $point,
            'status' => InspectionStatus::Ok,
            'note' => null,
            'position' => $point->position(),
        ];
    }

    public function point(InspectionPoint $point): static
    {
        return $this->state(fn () => ['point' => $point, 'position' => $point->position()]);
    }

    public function status(InspectionStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
