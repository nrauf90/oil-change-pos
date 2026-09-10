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

class DraftSaleCompletionTest extends TestCase
{
    use RefreshDatabase;

    private const CLOCK = '2026-03-18 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::CLOCK));
        $this->actingAs(User::factory()->admin()->create());
    }

    private function draftWithLine(array $lineOverrides = []): Order
    {
        $order = Order::factory()->label('Bay 1')->create(['customer_name' => 'Bilal']);

        OrderLine::factory()->for($order)->create(array_merge([
            'item_name' => 'Oil filter',
            'type' => SaleLineType::Custom,
            'quantity' => 1,
            'manually_charged_price' => '450.00',
        ], $lineOverrides));

        return $order->refresh();
    }

    public function test_completing_writes_a_sale_from_the_order_lines(): void
    {
        $order = $this->draftWithLine();

        $this->post(route('orders.complete', $order))->assertRedirect();

        $sale = Sale::sole();

        $this->assertSame('Bilal', $sale->customer_name);
        $this->assertSame('Oil filter', $sale->lines->sole()->item_name);
        $this->assertSame('450.00', $sale->total_amount);
    }

    public function test_completing_carries_the_drafts_discount_onto_the_sale(): void
    {
        $order = $this->draftWithLine();
        $order->update(['discount' => '50.00']);

        $this->post(route('orders.complete', $order))->assertRedirect();

        $sale = Sale::sole();

        $this->assertSame('50.00', $sale->discount);
        $this->assertSame('400.00', $sale->total_amount);
    }

    public function test_a_completed_order_points_at_the_sale_it_produced(): void
    {
        $order = $this->draftWithLine();

        $this->post(route('orders.complete', $order))->assertRedirect();

        $order->refresh();

        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(Sale::sole()->id, $order->sale_id);
        $this->assertFalse($order->isDraft());
    }

    public function test_completing_draws_stock_exactly_once(): void
    {
        $item = Item::factory()->create(['stock_level' => 10]);
        $order = $this->draftWithLine([
            'item_id' => $item->id,
            'item_name' => $item->name,
            'type' => SaleLineType::Product,
            'quantity' => 3,
        ]);

        $this->post(route('orders.complete', $order))->assertRedirect();

        $this->assertSame('7.000', $item->refresh()->stock_level);
    }

    public function test_an_empty_draft_cannot_be_completed(): void
    {
        $order = Order::factory()->create();

        $this->post(route('orders.complete', $order))->assertStatus(422);

        $this->assertDatabaseCount('sales', 0, 'tenant');
        $this->assertTrue($order->refresh()->isDraft());
    }

    public function test_a_completed_order_cannot_be_completed_twice(): void
    {
        $order = $this->draftWithLine();

        $this->post(route('orders.complete', $order))->assertRedirect();
        $this->post(route('orders.complete', $order))->assertNotFound();

        $this->assertDatabaseCount('sales', 1, 'tenant');
    }

    public function test_a_draft_has_no_invoice_number_before_completion(): void
    {
        $order = $this->draftWithLine();

        $this->assertNull($order->sale_id);
        $this->assertDatabaseCount('sales', 0, 'tenant');
    }

    public function test_the_invoice_becomes_reachable_only_after_completion(): void
    {
        $order = $this->draftWithLine();

        $this->post(route('orders.complete', $order))->assertRedirect();

        $sale = Sale::sole();

        $this->get(route('sales.show', $sale))->assertOk()->assertSee($sale->invoice_number);
    }

    public function test_a_completed_bill_leaves_the_draft_list(): void
    {
        $order = $this->draftWithLine();

        $this->post(route('orders.complete', $order))->assertRedirect();

        $this->get(route('orders.index'))->assertOk()->assertSee('No draft bills open');
    }

    public function test_completing_one_bill_leaves_the_other_bays_untouched(): void
    {
        $first = $this->draftWithLine();
        $second = Order::factory()->label('Bay 2')->create();
        OrderLine::factory()->for($second)->price('900.00')->create();

        $this->post(route('orders.complete', $first))->assertRedirect();

        $this->assertTrue($second->refresh()->isDraft());
        $this->assertDatabaseCount('sales', 1, 'tenant');
    }
}
