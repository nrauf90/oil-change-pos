<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\SaleLineType;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DraftSaleCounterTest extends TestCase
{
    use RefreshDatabase;

    private const CLOCK = '2026-03-18 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::CLOCK));
        $this->actingAs(User::factory()->admin()->create());
    }

    /** @return array<string, mixed> */
    private function draftPayload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Bay 2',
            'customer_name' => 'Bilal',
            'phone' => '03001234567',
            'lines' => [[
                'item_id' => null,
                'item_name' => 'Oil filter',
                'type' => SaleLineType::Custom->value,
                'quantity' => 1,
                'manually_charged_price' => '450.00',
            ]],
        ], $overrides);
    }

    public function test_a_draft_is_saved_from_the_counter(): void
    {
        $this->post(route('orders.store'), $this->draftPayload())
            ->assertRedirect(route('orders.index'));

        $order = Order::sole();

        $this->assertSame('Bay 2', $order->label);
        $this->assertSame(OrderStatus::Draft, $order->status);
        $this->assertSame('Oil filter', $order->lines->sole()->item_name);
    }

    public function test_a_draft_saves_with_no_customer_details(): void
    {
        $this->post(route('orders.store'), $this->draftPayload([
            'customer_name' => null,
            'phone' => null,
        ]))->assertRedirect();

        $order = Order::sole();

        $this->assertNull($order->customer_name);
        $this->assertNull($order->phone);
    }

    public function test_a_draft_saves_with_no_lines(): void
    {
        $this->post(route('orders.store'), $this->draftPayload(['lines' => []]))->assertRedirect();

        $this->assertCount(0, Order::sole()->lines);
    }

    public function test_a_draft_draws_no_stock(): void
    {
        $item = Item::factory()->create(['stock_level' => 10]);

        $this->post(route('orders.store'), $this->draftPayload([
            'lines' => [[
                'item_id' => $item->id,
                'item_name' => $item->name,
                'type' => SaleLineType::Product->value,
                'quantity' => 3,
                'manually_charged_price' => '1500.00',
            ]],
        ]))->assertRedirect();

        $this->assertSame('10.000', $item->refresh()->stock_level);
    }

    public function test_the_creator_is_taken_from_the_session_not_the_payload(): void
    {
        $someoneElse = User::factory()->create();

        $this->post(route('orders.store'), $this->draftPayload(['user_id' => $someoneElse->id]))
            ->assertRedirect();

        $this->assertSame(auth()->id(), Order::sole()->user_id);
    }

    public function test_a_line_without_a_name_is_rejected(): void
    {
        $this->post(route('orders.store'), $this->draftPayload([
            'lines' => [[
                'item_id' => null,
                'item_name' => '',
                'type' => SaleLineType::Custom->value,
                'quantity' => 1,
                'manually_charged_price' => '450.00',
            ]],
        ]))->assertSessionHasErrors('lines.0.item_name');

        $this->assertDatabaseCount('orders', 0, 'tenant');
    }

    public function test_the_draft_list_loads_with_an_empty_state(): void
    {
        $this->get(route('orders.index'))
            ->assertOk()
            ->assertViewIs('orders.index')
            ->assertSee('No draft bills open');
    }

    public function test_drafts_are_visible_to_the_whole_counter(): void
    {
        $colleague = User::factory()->create(['name' => 'Counter Ali']);
        Order::factory()->for($colleague, 'user')->label('Bay 4')->create();

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertSee('Bay 4')
            ->assertSee('Counter Ali');
    }

    public function test_the_list_excludes_completed_orders(): void
    {
        Order::factory()->label('Still open')->create();
        Order::factory()->label('Already billed')->create()->forceFill([
            'status' => OrderStatus::Completed,
            'sale_id' => Sale::factory()->create()->id,
        ])->save();

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertSee('Still open')
            ->assertDontSee('Already billed');
    }

    public function test_the_list_shows_the_running_total(): void
    {
        $order = Order::factory()->label('Bay 1')->create();
        OrderLine::factory()->for($order)->price('1200.50')->create();
        OrderLine::factory()->for($order)->price('300.00')->create();

        $this->get(route('orders.index'))->assertOk()->assertSee('1,500.50');
    }

    public function test_an_existing_draft_is_updated(): void
    {
        $order = Order::factory()->label('Bay 1')->create();
        OrderLine::factory()->for($order)->create(['item_name' => 'Old line']);

        $this->put(route('orders.update', $order), $this->draftPayload([
            'label' => 'Bay 3',
            'version' => $order->version,
        ]))->assertRedirect(route('orders.index'));

        $order->refresh();

        $this->assertSame('Bay 3', $order->label);
        $this->assertSame('Oil filter', $order->lines->sole()->item_name);
    }

    public function test_updating_a_draft_never_rewrites_who_opened_it(): void
    {
        $opener = User::factory()->create();
        $order = Order::factory()->for($opener, 'user')->create();

        $this->put(route('orders.update', $order), $this->draftPayload(['version' => $order->version]))
            ->assertRedirect();

        $this->assertSame($opener->id, $order->refresh()->user_id);
    }

    public function test_a_completed_order_cannot_be_updated(): void
    {
        $order = Order::factory()->create();
        $order->forceFill(['status' => OrderStatus::Completed, 'sale_id' => Sale::factory()->create()->id])->save();

        $this->put(route('orders.update', $order), $this->draftPayload())->assertNotFound();
    }

    public function test_a_draft_is_deleted(): void
    {
        $order = Order::factory()->create();
        OrderLine::factory()->for($order)->create();

        $this->delete(route('orders.destroy', $order))->assertRedirect(route('orders.index'));

        $this->assertDatabaseCount('orders', 0, 'tenant');
        $this->assertDatabaseCount('order_lines', 0, 'tenant');
    }

    public function test_a_completed_order_cannot_be_deleted_through_the_draft_route(): void
    {
        $order = Order::factory()->create();
        $order->forceFill(['status' => OrderStatus::Completed, 'sale_id' => Sale::factory()->create()->id])->save();

        $this->delete(route('orders.destroy', $order))->assertNotFound();

        $this->assertDatabaseCount('orders', 1, 'tenant');
    }
}
