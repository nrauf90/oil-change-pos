<?php

namespace Tests\Feature;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Http\Controllers\PosController;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The counter's category rail — which shelf each tile lands on.
 *
 * The rail is derived, not configured, so the only thing that keeps it honest
 * is that every shelf it advertises can actually be reached. A category that
 * can never hold anything is worse than no category: it reads as an empty
 * shelf the counter keeps checking.
 */
class PosCategoryRailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    private function shelfFor(Item $item): string
    {
        return PosController::present($item, showCost: false)['group'];
    }

    /**
     * The one this rail exists for. AC gas is billed as labour but drawn by the
     * kilo out of a cylinder, and it is the only kind of thing the shop weighs —
     * so filing it under Services by its type left the AC gas shelf permanently
     * empty and hid the gas among the wrench jobs.
     */
    public function test_a_repair_drawn_by_the_kilo_sits_on_the_gas_shelf(): void
    {
        $refill = Item::factory()->repair()->create([
            'name' => 'AC Gas Refill',
            'unit_of_measure' => UnitOfMeasure::Kilogram,
            'stock_level' => 13,
        ]);

        $this->assertSame('gas', $this->shelfFor($refill));
    }

    public function test_a_repair_drawn_by_the_litre_sits_with_the_oils(): void
    {
        $flush = Item::factory()->repair()->create([
            'name' => 'Coolant Flush — Supplied',
            'unit_of_measure' => UnitOfMeasure::Litre,
            'stock_level' => 20,
        ]);

        $this->assertSame('oil', $this->shelfFor($flush));
    }

    public function test_labour_that_pours_nothing_stays_a_service(): void
    {
        $labour = Item::factory()->repair()->create(['name' => 'Wheel Alignment']);

        $this->assertSame('service', $this->shelfFor($labour));
    }

    public function test_a_product_counted_in_pieces_is_a_part(): void
    {
        $filter = Item::factory()->tracked(24, 6)->create(['name' => 'Oil Filter']);

        $this->assertSame('part', $this->shelfFor($filter));
    }

    public function test_a_product_poured_by_the_litre_is_an_oil(): void
    {
        $oil = Item::factory()->create([
            'name' => 'ZIC X7 10W-40 (4L)',
            'unit_of_measure' => UnitOfMeasure::Litre,
            'stock_level' => 48,
        ]);

        $this->assertSame('oil', $this->shelfFor($oil));
    }

    /**
     * Whatever the shop stocks, the rail must be able to show it. If any shelf
     * the counter can tap is unreachable, the grouping rule has drifted from
     * the categories the screen advertises.
     */
    public function test_every_shelf_the_rail_offers_can_actually_be_reached(): void
    {
        $reachable = [];

        foreach (UnitOfMeasure::cases() as $measure) {
            foreach (ItemType::cases() as $type) {
                $item = Item::factory()->make([
                    'type' => $type,
                    'unit_of_measure' => $measure,
                ]);

                $reachable[] = PosController::present($item, showCost: false)['group'];
            }
        }

        $this->assertEqualsCanonicalizing(
            ['oil', 'gas', 'part', 'service'],
            array_values(array_unique($reachable)),
        );
    }

    public function test_the_sale_screen_seeds_the_gas_shelf_with_the_item_that_belongs_on_it(): void
    {
        Item::factory()->repair()->create([
            'name' => 'AC Gas Refill',
            'unit_of_measure' => UnitOfMeasure::Kilogram,
            'stock_level' => 13,
        ]);

        $seeded = $this->get(route('pos.create'))->assertOk()->viewData('items');

        $this->assertSame('gas', $seeded->firstWhere('name', 'AC Gas Refill')['group']);
    }

    /** Every shelf named in the rail is one the seeded tiles can be filed under. */
    public function test_no_tile_is_seeded_onto_a_shelf_the_rail_does_not_offer(): void
    {
        Item::factory()->count(5)->create();
        Item::factory()->repair()->count(3)->create();

        $response = $this->get(route('pos.create'))->assertOk();

        $shelves = array_column($response->viewData('groups'), 'key');

        foreach ($response->viewData('items') as $tile) {
            $this->assertContains($tile['group'], $shelves);
        }
    }
}
