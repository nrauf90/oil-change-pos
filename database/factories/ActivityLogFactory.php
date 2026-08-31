<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ActivityLog> */
class ActivityLogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'user_name' => $this->faker->name(),
            'action' => $this->faker->randomElement(ActivityLog::ACTIONS),
            'subject_type' => null,
            'subject_id' => null,
            'description' => $this->faker->sentence(6),
            'properties' => null,
            'created_at' => now(),
        ];
    }

    public function action(string $action): static
    {
        return $this->state(fn () => ['action' => $action]);
    }

    /** Written with nobody signed in — a console or queue write. */
    public function bySystem(): static
    {
        return $this->state(fn () => ['user_id' => null, 'user_name' => 'System']);
    }

    public function by(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id, 'user_name' => $user->name]);
    }
}
