<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftSalePermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'label' => 'Bay 2',
            'lines' => [[
                'item_id' => null,
                'item_name' => 'Oil filter',
                'type' => 'custom',
                'quantity' => 1,
                'manually_charged_price' => '450.00',
            ]],
        ];
    }

    public function test_a_technician_cannot_see_or_create_drafts(): void
    {
        $this->actingAs(User::factory()->technician()->create());

        $this->get(route('orders.index'))->assertForbidden();
        $this->post(route('orders.store'), $this->payload())->assertForbidden();
    }

    public function test_a_manager_can_create_and_complete_a_draft(): void
    {
        $this->actingAs(User::factory()->manager()->create());

        $this->post(route('orders.store'), $this->payload())->assertRedirect();

        $order = Order::sole();

        $this->post(route('orders.complete', $order))->assertRedirect();
        $this->assertFalse($order->refresh()->isDraft());
    }

    public function test_a_manager_cannot_discard_a_draft(): void
    {
        $order = Order::factory()->create();
        OrderLine::factory()->for($order)->create();

        $this->actingAs(User::factory()->manager()->create())
            ->delete(route('orders.destroy', $order))
            ->assertForbidden();

        $this->assertDatabaseCount('orders', 1, 'tenant');
    }

    public function test_an_admin_can_discard_a_draft(): void
    {
        $order = Order::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('orders.destroy', $order))
            ->assertRedirect(route('orders.index'));

        $this->assertDatabaseCount('orders', 0, 'tenant');
    }

    public function test_a_guest_is_redirected_to_login_from_every_draft_route(): void
    {
        $order = Order::factory()->create();

        $this->get(route('orders.index'))->assertRedirect(route('login'));
        $this->post(route('orders.store'), $this->payload())->assertRedirect(route('login'));
        $this->put(route('orders.update', $order), $this->payload())->assertRedirect(route('login'));
        $this->post(route('orders.complete', $order))->assertRedirect(route('login'));
        $this->delete(route('orders.destroy', $order))->assertRedirect(route('login'));
    }
}
