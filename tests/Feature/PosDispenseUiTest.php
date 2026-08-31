<?php

namespace Tests\Feature;

use App\Enums\UnitOfMeasure;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The counter screen has to ask "how many litres?" for oil and "how many kg?"
 * for gas, but must never put that figure on the customer's invoice.
 */
class PosDispenseUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    private function oil(): Item
    {
        return Item::factory()->create([
            'name' => 'ZIC X7 10W-40',
            'unit_of_measure' => UnitOfMeasure::Litre,
            'pack_label' => 'Carton',
            'units_per_pack' => 4,
            'measure_per_unit' => 4,
            'stock_level' => 32,
        ]);
    }

    public function test_the_sale_screen_tells_the_browser_how_each_item_is_measured(): void
    {
        $this->oil();

        $seed = $this->get(route('pos.create'))->assertOk()->getContent();

        $this->assertStringContainsString('unit_of_measure', $seed);
        $this->assertStringContainsString('litre', $seed);
    }

    public function test_the_sale_screen_carries_the_unit_abbreviation_for_display(): void
    {
        $this->oil();

        $this->get(route('pos.create'))->assertOk()->assertSee('unit_abbreviation', false);
    }

    public function test_a_cart_row_posts_a_dispensed_amount_field(): void
    {
        $this->oil();

        $this->get(route('pos.create'))
            ->assertOk()
            ->assertSee('dispensed_quantity', false);
    }

    public function test_the_quick_item_json_reports_how_a_new_item_is_measured(): void
    {
        $this->oil();

        $this->getJson(route('quick-items.index'))
            ->assertOk()
            ->assertJsonPath('data.0.unit_of_measure', 'litre')
            ->assertJsonPath('data.0.unit_abbreviation', 'L')
            ->assertJsonPath('data.0.is_measured', true);
    }

    public function test_a_piece_item_is_reported_as_unmeasured(): void
    {
        Item::factory()->create(['name' => 'Oil Filter', 'stock_level' => 5]);

        $this->getJson(route('quick-items.index'))
            ->assertOk()
            ->assertJsonPath('data.0.unit_of_measure', 'piece')
            ->assertJsonPath('data.0.is_measured', false);
    }
}
