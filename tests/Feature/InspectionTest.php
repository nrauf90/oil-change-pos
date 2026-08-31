<?php

namespace Tests\Feature;

use App\Enums\InspectionPoint;
use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\Sale;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Workshop floor: "log multi-point inspection notes" (PRD §1).
 *
 * An inspection is a condition report, never a quote — it carries no prices,
 * and the technician who may write one still may not raise a bill.
 */
class InspectionTest extends TestCase
{
    use RefreshDatabase;

    private function technician(): User
    {
        return User::factory()->technician()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'vehicle_plate' => 'LEB-4477',
            'vehicle_model' => 'Honda Civic 2021',
            'customer_name' => 'Bilal Khan',
            'phone' => '0311-999 8888',
            'mileage' => 74300,
            'notes' => 'Rear left tyre losing pressure overnight.',
            'points' => [
                'engine_oil' => ['status' => 'ok'],
                'brake_pads' => ['status' => 'attention', 'note' => 'Fronts down to 3mm'],
                'battery' => ['status' => 'urgent', 'note' => 'Will not hold a charge'],
            ],
        ], $overrides);
    }

    /* ---------------------------------------------------------------- */
    /* The list */
    /* ---------------------------------------------------------------- */

    public function test_the_inspection_list_loads_with_an_empty_state(): void
    {
        $this->actingAs($this->technician())
            ->get(route('inspections.index'))
            ->assertOk()
            ->assertSee('Inspections')
            ->assertSee('No inspections logged yet');
    }

    public function test_the_list_shows_inspections_newest_first(): void
    {
        $older = Inspection::factory()->create(['vehicle_plate' => 'AAA-111']);
        $older->forceFill(['inspected_at' => now()->subWeek()])->save();

        $newer = Inspection::factory()->create(['vehicle_plate' => 'ZZZ-999']);
        $newer->forceFill(['inspected_at' => now()])->save();

        $this->actingAs($this->technician())
            ->get(route('inspections.index'))
            ->assertOk()
            ->assertSeeInOrder(['ZZZ-999', 'AAA-111']);
    }

    /* ---------------------------------------------------------------- */
    /* Creating */
    /* ---------------------------------------------------------------- */

    public function test_a_technician_can_reach_the_list_and_create_screens(): void
    {
        $technician = $this->technician();

        $this->actingAs($technician)->get(route('inspections.index'))->assertOk();
        $this->actingAs($technician)->get(route('inspections.create'))->assertOk();
    }

    public function test_the_create_screen_offers_every_standard_check_point(): void
    {
        $response = $this->actingAs($this->technician())
            ->get(route('inspections.create'))
            ->assertOk();

        foreach (InspectionPoint::cases() as $point) {
            $response->assertSee($point->label());
        }

        foreach (['Engine Oil', 'Brake Pads', 'Tyre Tread', 'Wiper Blades', 'Battery', 'Coolant'] as $expected) {
            $response->assertSee($expected);
        }
    }

    public function test_a_technician_can_create_an_inspection_with_check_point_statuses_and_notes(): void
    {
        $technician = $this->technician();

        $this->actingAs($technician)
            ->post(route('inspections.store'), $this->payload())
            ->assertRedirect();

        $inspection = Inspection::query()->firstOrFail();

        $this->assertSame('LEB-4477', $inspection->vehicle_plate);
        $this->assertSame('Honda Civic 2021', $inspection->vehicle_model);
        $this->assertSame('Bilal Khan', $inspection->customer_name);
        $this->assertSame(74300, $inspection->mileage);
        $this->assertSame('Rear left tyre losing pressure overnight.', $inspection->notes);
        $this->assertNotNull($inspection->inspected_at);
        $this->assertCount(3, $inspection->points);

        $this->assertDatabaseHas('inspection_items', [
            'inspection_id' => $inspection->id,
            'point' => 'engine_oil',
            'status' => InspectionStatus::Ok->value,
        ]);
        $this->assertDatabaseHas('inspection_items', [
            'inspection_id' => $inspection->id,
            'point' => 'brake_pads',
            'status' => InspectionStatus::NeedsAttention->value,
            'note' => 'Fronts down to 3mm',
        ]);
        $this->assertDatabaseHas('inspection_items', [
            'inspection_id' => $inspection->id,
            'point' => 'battery',
            'status' => InspectionStatus::Urgent->value,
            'note' => 'Will not hold a charge',
        ]);
    }

    public function test_an_inspection_can_optionally_be_linked_to_a_sale(): void
    {
        $sale = Sale::factory()->create();

        $this->actingAs($this->technician())
            ->post(route('inspections.store'), $this->payload(['sale_id' => $sale->id]))
            ->assertRedirect();

        $this->assertSame($sale->id, Inspection::query()->firstOrFail()->sale_id);
    }

    public function test_a_sale_id_that_does_not_exist_is_rejected(): void
    {
        $this->actingAs($this->technician())
            ->post(route('inspections.store'), $this->payload(['sale_id' => 9999]))
            ->assertSessionHasErrors('sale_id');
    }

    /* ---------------------------------------------------------------- */
    /* The inspector is the signed-in user, never the request body */
    /* ---------------------------------------------------------------- */

    public function test_inspected_by_comes_from_the_signed_in_user_not_the_request_body(): void
    {
        $technician = $this->technician();
        $impostor = User::factory()->admin()->create();

        $this->actingAs($technician)
            ->post(route('inspections.store'), $this->payload([
                'inspected_by' => $impostor->id,
                'user_id' => $impostor->id,
            ]))
            ->assertRedirect();

        $inspection = Inspection::query()->firstOrFail();

        $this->assertSame($technician->id, $inspection->inspected_by);
        $this->assertNotSame($impostor->id, $inspection->inspected_by);
    }

    public function test_updating_an_inspection_never_reassigns_the_inspector(): void
    {
        $original = $this->technician();
        $inspection = Inspection::factory()->for($original, 'inspector')->create();

        $other = User::factory()->technician()->create();

        $this->actingAs($other)
            ->put(route('inspections.update', $inspection), $this->payload([
                'inspected_by' => $other->id,
            ]))
            ->assertRedirect();

        $this->assertSame($original->id, $inspection->fresh()->inspected_by);
    }

    /* ---------------------------------------------------------------- */
    /* Validation */
    /* ---------------------------------------------------------------- */

    public function test_the_vehicle_plate_is_required(): void
    {
        $this->actingAs($this->technician())
            ->post(route('inspections.store'), $this->payload(['vehicle_plate' => '']))
            ->assertSessionHasErrors('vehicle_plate');

        $this->assertDatabaseCount('inspections', 0);
    }

    public function test_an_unknown_check_point_status_is_rejected(): void
    {
        $this->actingAs($this->technician())
            ->post(route('inspections.store'), $this->payload([
                'points' => ['engine_oil' => ['status' => 'catastrophic']],
            ]))
            ->assertSessionHasErrors('points.engine_oil.status');

        $this->assertDatabaseCount('inspections', 0);
    }

    public function test_an_unknown_check_point_is_rejected(): void
    {
        $this->actingAs($this->technician())
            ->post(route('inspections.store'), $this->payload([
                'points' => ['flux_capacitor' => ['status' => 'ok']],
            ]))
            ->assertSessionHasErrors('points');

        $this->assertDatabaseCount('inspections', 0);
    }

    public function test_at_least_one_check_point_must_be_recorded(): void
    {
        $this->actingAs($this->technician())
            ->post(route('inspections.store'), $this->payload(['points' => []]))
            ->assertSessionHasErrors('points');

        $this->assertDatabaseCount('inspections', 0);
    }

    public function test_mileage_must_be_an_integer(): void
    {
        $this->actingAs($this->technician())
            ->post(route('inspections.store'), $this->payload(['mileage' => 'seventy thousand']))
            ->assertSessionHasErrors('mileage');

        $this->assertDatabaseCount('inspections', 0);
    }

    public function test_mileage_cannot_be_negative(): void
    {
        $this->actingAs($this->technician())
            ->post(route('inspections.store'), $this->payload(['mileage' => -5]))
            ->assertSessionHasErrors('mileage');

        $this->assertDatabaseCount('inspections', 0);
    }

    public function test_mileage_is_optional(): void
    {
        $this->actingAs($this->technician())
            ->post(route('inspections.store'), $this->payload(['mileage' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(Inspection::query()->firstOrFail()->mileage);
    }

    /* ---------------------------------------------------------------- */
    /* Editing */
    /* ---------------------------------------------------------------- */

    public function test_the_edit_screen_loads_with_the_recorded_statuses(): void
    {
        $inspection = Inspection::factory()->create(['vehicle_plate' => 'LEB-4477']);
        $inspection->points()->create(['point' => 'brake_pads', 'status' => InspectionStatus::Urgent, 'note' => 'Metal on metal']);

        $this->actingAs($this->technician())
            ->get(route('inspections.edit', $inspection))
            ->assertOk()
            ->assertSee('LEB-4477')
            ->assertSee('Metal on metal');
    }

    public function test_an_inspection_can_be_updated(): void
    {
        $inspection = Inspection::factory()->create([
            'vehicle_plate' => 'AAA-111', 'mileage' => 1000, 'notes' => 'Original note',
        ]);
        $inspection->points()->create(['point' => 'engine_oil', 'status' => InspectionStatus::Ok]);

        $this->actingAs($this->technician())
            ->put(route('inspections.update', $inspection), $this->payload([
                'vehicle_plate' => 'BBB-222',
                'mileage' => 91500,
                'notes' => 'Revised after road test',
            ]))
            ->assertRedirect();

        $inspection->refresh();

        $this->assertSame('BBB-222', $inspection->vehicle_plate);
        $this->assertSame(91500, $inspection->mileage);
        $this->assertSame('Revised after road test', $inspection->notes);
    }

    /**
     * The sheet is submitted whole, so the edit form must carry the existing
     * invoice link back or a technician fixing a typo silently unlinks it.
     */
    public function test_the_edit_form_carries_the_linked_invoice_back(): void
    {
        $sale = Sale::factory()->create();
        $inspection = Inspection::factory()->for($sale)->create();

        $this->actingAs($this->technician())
            ->get(route('inspections.edit', $inspection))
            ->assertOk()
            ->assertSee('name="sale_id"', escape: false)
            ->assertSee('value="'.$sale->id.'"', escape: false);
    }

    public function test_updating_replaces_the_recorded_check_points(): void
    {
        $inspection = Inspection::factory()->create();
        $inspection->points()->create(['point' => 'coolant', 'status' => InspectionStatus::Urgent, 'note' => 'Boiling over']);

        $this->actingAs($this->technician())
            ->put(route('inspections.update', $inspection), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseMissing('inspection_items', [
            'inspection_id' => $inspection->id, 'point' => 'coolant',
        ]);
        $this->assertDatabaseHas('inspection_items', [
            'inspection_id' => $inspection->id, 'point' => 'engine_oil',
        ]);
        $this->assertSame(3, $inspection->fresh()->points()->count());
    }

    /* ---------------------------------------------------------------- */
    /* Finding by plate, and reading one back */
    /* ---------------------------------------------------------------- */

    public function test_inspections_can_be_found_by_plate(): void
    {
        Inspection::factory()->create(['vehicle_plate' => 'LEB-4477', 'customer_name' => 'Bilal Khan']);
        Inspection::factory()->create(['vehicle_plate' => 'ZZZ-999', 'customer_name' => 'Sana Malik']);

        $this->actingAs($this->technician())
            ->get(route('inspections.index', ['plate' => 'LEB-4477']))
            ->assertOk()
            ->assertSee('Bilal Khan')
            ->assertDontSee('Sana Malik');
    }

    public function test_a_plate_search_ignores_dashes_spaces_and_case(): void
    {
        Inspection::factory()->create(['vehicle_plate' => 'LEB-4477', 'customer_name' => 'Bilal Khan']);

        $this->actingAs($this->technician())
            ->get(route('inspections.index', ['plate' => 'leb 4477']))
            ->assertOk()
            ->assertSee('Bilal Khan');
    }

    public function test_a_percent_plate_search_does_not_return_unrelated_inspections(): void
    {
        Inspection::factory()->create(['vehicle_plate' => 'LEB-4477', 'customer_name' => 'Bilal Khan']);

        $this->actingAs($this->technician())
            ->get(route('inspections.index', ['plate' => '%']))
            ->assertOk()
            ->assertDontSee('Bilal Khan');
    }

    public function test_an_inspection_renders_its_check_points_with_their_statuses(): void
    {
        $inspection = Inspection::factory()->create(['vehicle_plate' => 'LEB-4477', 'mileage' => 74300]);
        $inspection->points()->createMany([
            ['point' => 'engine_oil', 'status' => InspectionStatus::Ok],
            ['point' => 'brake_pads', 'status' => InspectionStatus::NeedsAttention, 'note' => 'Fronts down to 3mm'],
            ['point' => 'battery', 'status' => InspectionStatus::Urgent, 'note' => 'Will not hold a charge'],
        ]);

        $this->actingAs($this->technician())
            ->get(route('inspections.show', $inspection))
            ->assertOk()
            ->assertSee('LEB-4477')
            ->assertSee('74,300 km')
            ->assertSee('Engine Oil')
            ->assertSee(InspectionStatus::Ok->label())
            ->assertSee('Brake Pads')
            ->assertSee(InspectionStatus::NeedsAttention->label())
            ->assertSee('Fronts down to 3mm')
            ->assertSee('Battery')
            ->assertSee(InspectionStatus::Urgent->label())
            ->assertSee('Will not hold a charge');
    }

    public function test_an_inspection_shows_who_carried_it_out(): void
    {
        $technician = User::factory()->technician()->create(['name' => 'Imran Shah']);
        $inspection = Inspection::factory()->for($technician, 'inspector')->create();

        $this->actingAs($this->technician())
            ->get(route('inspections.show', $inspection))
            ->assertOk()
            ->assertSee('Imran Shah');
    }

    /** An inspection is a condition report, not a quote. */
    public function test_an_inspection_never_shows_a_price_even_when_linked_to_a_sale(): void
    {
        $sale = Sale::factory()->create([
            'total_amount' => 4242.42, 'labor_charge' => 1313.13, 'misc_charge' => 777.77,
        ]);
        $inspection = Inspection::factory()->for($sale)->create(['vehicle_plate' => 'LEB-4477']);
        $inspection->points()->create(['point' => 'engine_oil', 'status' => InspectionStatus::Ok]);

        $response = $this->actingAs($this->technician())
            ->get(route('inspections.show', $inspection))
            ->assertOk();

        foreach (['4,242.42', '4242.42', '1,313.13', '1313.13', '777.77'] as $amount) {
            $response->assertDontSee($amount);
        }
    }

    /* ---------------------------------------------------------------- */
    /* A technician still cannot raise a bill */
    /* ---------------------------------------------------------------- */

    public function test_a_technician_is_forbidden_from_the_pos_screen(): void
    {
        $this->actingAs($this->technician())
            ->get(route('pos.create'))
            ->assertForbidden();
    }

    public function test_a_technician_is_forbidden_from_storing_a_sale(): void
    {
        $this->actingAs($this->technician())
            ->post(route('sales.store'), [])
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------- */
    /* Access control */
    /* ---------------------------------------------------------------- */

    public function test_a_guest_is_redirected_to_login_from_every_inspection_route(): void
    {
        $inspection = Inspection::factory()->create();

        $this->get(route('inspections.index'))->assertRedirect(route('login'));
        $this->get(route('inspections.create'))->assertRedirect(route('login'));
        $this->get(route('inspections.show', $inspection))->assertRedirect(route('login'));
        $this->get(route('inspections.edit', $inspection))->assertRedirect(route('login'));
        $this->post(route('inspections.store'), $this->payload())->assertRedirect(route('login'));
        $this->put(route('inspections.update', $inspection), $this->payload())->assertRedirect(route('login'));
    }

    public function test_inspection_routes_404_when_the_workshop_module_is_switched_off(): void
    {
        $inspection = Inspection::factory()->create();
        $technician = $this->technician();

        app(ModuleRegistry::class)->setEnabled('workshop', false);

        $this->actingAs($technician)->get(route('inspections.index'))->assertNotFound();
        $this->actingAs($technician)->get(route('inspections.create'))->assertNotFound();
        $this->actingAs($technician)->get(route('inspections.show', $inspection))->assertNotFound();
        $this->actingAs($technician)->get(route('inspections.edit', $inspection))->assertNotFound();
        $this->actingAs($technician)->post(route('inspections.store'), $this->payload())->assertNotFound();
        $this->actingAs($technician)->put(route('inspections.update', $inspection), $this->payload())->assertNotFound();
    }
}
