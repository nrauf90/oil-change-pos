<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DraftSaleTest extends TestCase
{
    use RefreshDatabase;

    /** Wednesday, mid-week and mid-month, so every boundary has room either side. */
    private const CLOCK = '2026-03-18 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::CLOCK));
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_an_order_holds_lines_with_a_price_snapshot(): void
    {
        $order = Order::factory()->create();
        OrderLine::factory()->for($order)->price('450.75')->create(['item_name' => 'Oil filter']);

        $line = $order->refresh()->lines->sole();

        $this->assertSame('Oil filter', $line->item_name);
        $this->assertSame('450.75', $line->manually_charged_price);
    }

    public function test_an_order_starts_as_a_draft_with_no_sale(): void
    {
        $order = Order::factory()->create();

        $this->assertSame(OrderStatus::Draft, $order->status);
        $this->assertNull($order->sale_id);
        $this->assertTrue($order->isDraft());
    }

    public function test_an_order_records_who_created_it(): void
    {
        $order = Order::factory()->for(User::factory()->create(['name' => 'Counter Ali']), 'user')->create();

        $this->assertSame('Counter Ali', $order->user->name);
    }

    public function test_a_completed_order_is_not_a_draft(): void
    {
        $order = Order::factory()->create();
        $order->forceFill(['status' => OrderStatus::Completed, 'sale_id' => Sale::factory()->create()->id])->save();

        $this->assertFalse($order->refresh()->isDraft());
    }

    public function test_the_display_label_prefers_the_typed_label(): void
    {
        $order = Order::factory()->label('Bay 2')->create(['vehicle_plate' => 'ABC-123']);

        $this->assertSame('Bay 2', $order->displayLabel());
    }

    public function test_the_display_label_falls_back_to_the_plate_then_the_customer(): void
    {
        $plated = Order::factory()->create(['label' => null, 'vehicle_plate' => 'ABC-123']);
        $named = Order::factory()->create(['label' => null, 'vehicle_plate' => null, 'customer_name' => 'Bilal']);

        $this->assertSame('ABC-123', $plated->displayLabel());
        $this->assertSame('Bilal', $named->displayLabel());
    }

    public function test_the_display_label_falls_back_to_the_time_it_was_opened(): void
    {
        $order = Order::factory()->bare()->create(['label' => null]);

        $this->assertStringStartsWith('Started ', $order->displayLabel());
    }

    public function test_deleting_an_order_takes_its_lines(): void
    {
        $order = Order::factory()->create();
        OrderLine::factory()->for($order)->create();

        $order->delete();

        $this->assertDatabaseCount('order_lines', 0, 'tenant');
    }

    public function test_a_draft_left_open_too_long_is_flagged_as_stale(): void
    {
        $fresh = Order::factory()->create();
        $old = Order::factory()->create(['created_at' => Carbon::parse(self::CLOCK)->subDays(3)]);

        $this->assertFalse($fresh->isStale());
        $this->assertTrue($old->isStale());
    }

    public function test_the_drafts_scope_excludes_completed_orders(): void
    {
        Order::factory()->count(2)->create();
        Order::factory()->create()->forceFill([
            'status' => OrderStatus::Completed,
            'sale_id' => Sale::factory()->create()->id,
        ])->save();

        $this->assertCount(2, Order::query()->drafts()->get());
    }
}
