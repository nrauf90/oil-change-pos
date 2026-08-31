<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            // alphaDash usernames only: faker's userName() emits dots, which the
            // staff form rejects, so a factory-made user must be creatable there too.
            'username' => str($this->faker->unique()->userName())->replace(['.', ' '], '-')->lower()->toString(),
            'password' => Hash::make('password'),
            'is_active' => true,
            'remember_token' => str()->random(10),
        ];
    }

    public function admin(): static
    {
        return $this->withRole(Role::Admin);
    }

    public function manager(): static
    {
        return $this->withRole(Role::Manager);
    }

    public function technician(): static
    {
        return $this->withRole(Role::Technician);
    }

    public function withRole(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRoleEnum($role));
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
