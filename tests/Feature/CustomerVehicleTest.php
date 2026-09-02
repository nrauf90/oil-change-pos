<?php

namespace Tests\Feature;

use App\Models\CustomerVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerVehicleTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Ali Raza',
            'phone' => '03001234567',
            'vehicle_model' => 'Toyota Corolla 2018',
            'vehicle_plate' => 'abc-123',
            'mileage' => 84500,
            'lines' => [
                ['item_id' => null, 'item_name' => 'Oil change', 'type' => 'custom', 'manually_charged_price' => '1000'],
            ],
        ], $overrides);
    }

    public function test_completing_a_sale_saves_reusable_customer_and_vehicle_details(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('sales.store'), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('customer_vehicles', [
            'customer_name' => 'Ali Raza',
            'phone' => '03001234567',
            'vehicle_model' => 'Toyota Corolla 2018',
            'vehicle_plate' => 'ABC-123',
            'mileage' => 84500,
        ], 'tenant');
    }

    public function test_a_returning_vehicle_refreshes_its_saved_details_without_creating_a_duplicate(): void
    {
        $user = User::factory()->manager()->create();

        $this->actingAs($user)->post(route('sales.store'), $this->payload())->assertRedirect();
        $this->actingAs($user)->post(route('sales.store'), $this->payload([
            'customer_name' => 'Ali Ahmed',
            'vehicle_model' => '',
            'mileage' => 91000,
        ]))->assertRedirect();

        $this->assertDatabaseCount('customer_vehicles', 1, 'tenant');
        $this->assertDatabaseHas('customer_vehicles', [
            'customer_name' => 'Ali Ahmed',
            'vehicle_model' => 'Toyota Corolla 2018',
            'vehicle_plate' => 'ABC-123',
            'mileage' => 91000,
        ], 'tenant');
    }

    public function test_a_walk_in_sale_does_not_create_an_empty_saved_profile(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('sales.store'), $this->payload([
                'customer_name' => '',
                'phone' => '',
                'vehicle_model' => '',
                'vehicle_plate' => '',
                'mileage' => '',
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('customer_vehicles', 0, 'tenant');
    }

    public function test_the_sale_screen_offers_saved_profiles_for_selection(): void
    {
        CustomerVehicle::factory()->create([
            'customer_name' => 'Sana Malik',
            'vehicle_plate' => 'LEB-4477',
        ]);

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('pos.create'))
            ->assertOk()
            ->assertSee('Saved customer / vehicle')
            ->assertSee('Type 3+ characters: name, phone, plate, or vehicle')
            ->assertSee('Type at least 3 characters')
            ->assertSee('role="combobox"', false)
            ->assertSee('saved_customer_vehicle_results', false)
            ->assertSee('applyCustomerVehicle(profile)', false)
            ->assertSee('Sana Malik', false)
            ->assertSee('LEB-4477', false);
    }

    public function test_the_sale_screen_initially_loads_only_ten_recent_profiles(): void
    {
        CustomerVehicle::factory()->count(12)->create();

        $profiles = $this->actingAs(User::factory()->manager()->create())
            ->get(route('pos.create'))
            ->assertOk()
            ->viewData('customerVehicles');

        $this->assertCount(10, $profiles);
    }

    public function test_profile_search_starts_at_three_characters_and_returns_all_matches(): void
    {
        CustomerVehicle::factory()->count(11)->create(['customer_name' => 'Target Customer']);
        CustomerVehicle::factory()->create(['customer_name' => 'Sana Malik', 'vehicle_plate' => 'LEB-4477']);
        $user = User::factory()->manager()->create();

        $this->actingAs($user)
            ->getJson(route('customer-vehicles.index', ['q' => 'Al']))
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAs($user)
            ->getJson(route('customer-vehicles.index', ['q' => 'Target']))
            ->assertOk()
            ->assertJsonCount(11)
            ->assertJsonMissing(['customer_name' => 'Sana Malik']);

        $this->actingAs($user)
            ->getJson(route('customer-vehicles.index', ['q' => '447']))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['customer_name' => 'Sana Malik', 'vehicle_plate' => 'LEB-4477']);
    }
}
