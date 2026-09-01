<?php

namespace Database\Factories\Central;

use App\Models\Central\PlatformUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<PlatformUser>
 */
class PlatformUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'is_active' => true,
            'remember_token' => str()->random(10),
        ];
    }
}
