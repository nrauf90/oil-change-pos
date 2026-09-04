<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two counter staff with the same bay open is the ordinary case in a workshop,
 * not an edge case. Without a version check the second save silently discards
 * the first, and the person who lost their work never finds out.
 */
class DraftSaleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(int $version, string $label): array
    {
        return [
            'label' => $label,
            'version' => $version,
            'lines' => [[
                'item_id' => null,
                'item_name' => 'Oil filter',
                'type' => 'custom',
                'quantity' => 1,
                'manually_charged_price' => '450.00',
            ]],
        ];
    }

    public function test_a_successful_save_increments_the_version(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $order = Order::factory()->create();

        $this->put(route('orders.update', $order), $this->payload($order->version, 'Bay 2'))
            ->assertRedirect();

        $this->assertSame(2, $order->refresh()->version);
    }

    public function test_saving_a_stale_copy_is_refused(): void
    {
        $ali = User::factory()->admin()->create(['name' => 'Counter Ali']);
        $bilal = User::factory()->admin()->create(['name' => 'Counter Bilal']);

        $order = Order::factory()->create();
        $staleVersion = $order->version;

        // Ali saves first and moves the bill on to version 2.
        $this->actingAs($ali)
            ->put(route('orders.update', $order), $this->payload($staleVersion, 'Ali edit'))
            ->assertRedirect();

        // Bilal still has version 1 open and saves over the top of it.
        $this->actingAs($bilal)
            ->put(route('orders.update', $order), $this->payload($staleVersion, 'Bilal edit'))
            ->assertSessionHasErrors('version');

        $this->assertSame('Ali edit', $order->refresh()->label);
    }

    public function test_the_refusal_names_who_changed_it(): void
    {
        $ali = User::factory()->admin()->create(['name' => 'Counter Ali']);
        $bilal = User::factory()->admin()->create(['name' => 'Counter Bilal']);

        $order = Order::factory()->for($ali, 'user')->create();
        $staleVersion = $order->version;

        $this->actingAs($ali)
            ->put(route('orders.update', $order), $this->payload($staleVersion, 'Ali edit'))
            ->assertRedirect();

        $this->actingAs($bilal)
            ->put(route('orders.update', $order), $this->payload($staleVersion, 'Bilal edit'))
            ->assertSessionHasErrorsIn('default', ['version' => 'Counter Ali changed this bill while you had it open. Reopen it to see their changes.']);
    }

    public function test_a_refused_save_leaves_the_lines_alone(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $order = Order::factory()->create();
        OrderLine::factory()->for($order)->create(['item_name' => 'Original line']);
        $staleVersion = $order->version;

        $this->put(route('orders.update', $order), $this->payload($staleVersion, 'First'))->assertRedirect();
        $this->put(route('orders.update', $order), $this->payload($staleVersion, 'Second'))
            ->assertSessionHasErrors('version');

        $this->assertSame('Oil filter', $order->refresh()->lines->sole()->item_name);
    }
}
