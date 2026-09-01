<?php

namespace Database\Factories\Central;

use App\Enums\ShopStatus;
use App\Models\Central\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(2);

        return [
            'name' => ucfirst(str($slug)->replace('-', ' ')->toString()),
            'slug' => $slug,
            'status' => ShopStatus::Provisioning,
            'database_driver' => 'sqlite',
            'database_name' => database_path("tenants/{$slug}.sqlite"),
            'timezone' => 'Asia/Karachi',
            'currency' => 'PKR',
        ];
    }
}
