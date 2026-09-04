<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\SaleLineType;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftSalePosScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    private function draft(): Order
    {
        $order = Order::factory()->label('Bay 2')->create([
            'customer_name' => 'Bilal',
            'phone' => '03001234567',
            'vehicle_plate' => 'ABC-123',
        ]);

        OrderLine::factory()->for($order)->create([
            'item_name' => 'ZIC 10W-40',
            'type' => SaleLineType::Custom,
            'quantity' => 1,
            'manually_charged_price' => '6800.00',
        ]);

        return $order->refresh();
    }

    public function test_the_counter_opens_empty_without_a_draft(): void
    {
        $this->get(route('pos.create'))
            ->assertOk()
            ->assertViewHas('draft', null)
            ->assertSee('Save as draft');
    }

    public function test_opening_a_draft_loads_its_lines_into_the_counter(): void
    {
        $order = $this->draft();

        $this->get(route('pos.create', ['order' => $order->id]))
            ->assertOk()
            ->assertViewHas('draft.id', $order->id)
            ->assertSee('ZIC 10W-40');
    }

    public function test_opening_a_draft_loads_its_customer_details(): void
    {
        $order = $this->draft();

        $this->get(route('pos.create', ['order' => $order->id]))
            ->assertOk()
            ->assertSee('Bilal')
            ->assertSee('ABC-123');
    }

    public function test_opening_a_draft_with_no_customer_leaves_those_fields_empty(): void
    {
        $order = Order::factory()->bare()->label('Bay 5')->create();
        OrderLine::factory()->for($order)->create(['item_name' => 'Air filter']);

        $this->get(route('pos.create', ['order' => $order->id]))
            ->assertOk()
            ->assertViewHas('draft.id', $order->id)
            ->assertSee('Air filter');
    }

    public function test_opening_a_completed_order_falls_back_to_an_empty_counter(): void
    {
        $order = $this->draft();
        $order->forceFill(['status' => OrderStatus::Completed, 'sale_id' => Sale::factory()->create()->id])->save();

        $this->get(route('pos.create', ['order' => $order->id]))
            ->assertOk()
            ->assertViewHas('draft', null);
    }

    public function test_an_unknown_draft_falls_back_to_an_empty_counter(): void
    {
        $this->get(route('pos.create', ['order' => 9999]))
            ->assertOk()
            ->assertViewHas('draft', null);
    }

    public function test_reopening_a_draft_needs_the_permission_to_save_one(): void
    {
        $order = $this->draft();

        // Someone who may work the counter but not keep draft bills gets a
        // clean screen, not somebody else's half-built bill.
        $counterOnly = User::factory()->create();
        $counterOnly->givePermissionTo('pos.use');

        $this->actingAs($counterOnly)
            ->get(route('pos.create', ['order' => $order->id]))
            ->assertOk()
            ->assertViewHas('draft', null);
    }

    public function test_completing_from_the_counter_saves_the_latest_cart_and_bills_it(): void
    {
        $order = $this->draft();

        $this->put(route('orders.update', $order), [
            'label' => 'Bay 2',
            'customer_name' => 'Bilal',
            'version' => $order->version,
            'complete' => '1',
            'lines' => [[
                'item_id' => null,
                'item_name' => 'ZIC 10W-40',
                'type' => SaleLineType::Custom->value,
                'quantity' => 1,
                'manually_charged_price' => '6800.00',
            ], [
                'item_id' => null,
                'item_name' => 'Oil filter added at the last minute',
                'type' => SaleLineType::Custom->value,
                'quantity' => 1,
                'manually_charged_price' => '450.00',
            ]],
        ])->assertRedirect();

        $sale = Sale::sole();

        $this->assertCount(2, $sale->lines);
        $this->assertSame('7250.00', $sale->total_amount);
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
    }

    public function test_saving_without_the_complete_flag_leaves_the_bill_open(): void
    {
        $order = $this->draft();

        $this->put(route('orders.update', $order), [
            'label' => 'Bay 2',
            'version' => $order->version,
            'complete' => '',
            'lines' => [[
                'item_id' => null,
                'item_name' => 'ZIC 10W-40',
                'type' => SaleLineType::Custom->value,
                'quantity' => 1,
                'manually_charged_price' => '6800.00',
            ]],
        ])->assertRedirect(route('orders.index'));

        $this->assertTrue($order->refresh()->isDraft());
        $this->assertDatabaseCount('sales', 0, 'tenant');
    }
}
