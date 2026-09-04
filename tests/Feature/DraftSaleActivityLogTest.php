<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftSaleActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_opening_a_draft_is_logged(): void
    {
        $this->post(route('orders.store'), [
            'label' => 'Bay 2',
            'lines' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'draft_sale.created',
        ], 'tenant');
    }

    public function test_completing_a_draft_is_logged_as_a_completion_not_an_amendment(): void
    {
        $order = Order::factory()->label('Bay 1')->create();
        OrderLine::factory()->for($order)->create();

        $this->post(route('orders.complete', $order))->assertRedirect();

        $this->assertDatabaseHas('activity_logs', ['action' => 'draft_sale.completed'], 'tenant');
        $this->assertDatabaseMissing('activity_logs', ['action' => 'draft_sale.updated'], 'tenant');
    }

    public function test_discarding_a_draft_is_logged(): void
    {
        $order = Order::factory()->label('Bay 3')->create();

        $this->delete(route('orders.destroy', $order))->assertRedirect();

        $log = ActivityLog::where('action', 'draft_sale.deleted')->sole();

        $this->assertStringContainsString('Bay 3', $log->description);
    }
}
