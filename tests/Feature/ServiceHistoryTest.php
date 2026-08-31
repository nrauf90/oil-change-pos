<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Workshop floor: "lookup previous vehicle service history by phone number".
 *
 * The load-bearing rule under test here is the PRD's restriction on the
 * Technician role — they may read a vehicle's history but must never see a
 * price, a labor charge, a misc charge or a total.
 */
class ServiceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function technician(): User
    {
        return User::factory()->technician()->create();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** A past visit with deliberately distinctive amounts, so assertDontSee bites. */
    private function pricedSale(array $attributes = []): Sale
    {
        $sale = Sale::factory()->create($attributes + [
            'labor_charge' => 1313.13,
            'misc_charge' => 777.77,
            'total_amount' => 4242.42,
        ]);

        SaleItem::factory()->for($sale, 'sale')->create([
            'item_name' => 'ZIC 10W-40 Full Synthetic',
            'manually_charged_price' => 5151.51,
        ]);

        return $sale;
    }

    /* ---------------------------------------------------------------- */
    /* The lookup screen */
    /* ---------------------------------------------------------------- */

    public function test_the_lookup_page_loads_with_an_empty_state(): void
    {
        $this->actingAs($this->technician())
            ->get(route('service-history.index'))
            ->assertOk()
            ->assertSee('Vehicle Service History')
            ->assertSee('Enter a phone number or licence plate');
    }

    public function test_looking_up_a_phone_number_returns_that_customers_past_visits_newest_first(): void
    {
        $older = $this->pricedSale([
            'customer_name' => 'Ali Raza', 'phone' => '03001234567',
            'vehicle_plate' => 'AAA-111', 'mileage' => 60100,
        ]);
        $older->forceFill(['created_at' => now()->subMonths(6)])->save();

        $newer = $this->pricedSale([
            'customer_name' => 'Ali Raza', 'phone' => '03001234567',
            'vehicle_plate' => 'AAA-111', 'mileage' => 74300,
        ]);
        $newer->forceFill(['created_at' => now()->subDay()])->save();

        $this->actingAs($this->technician())
            ->get(route('service-history.index', ['q' => '03001234567']))
            ->assertOk()
            ->assertSee('Ali Raza')
            ->assertSee('74,300 km')
            ->assertSee('60,100 km')
            ->assertSeeInOrder([$newer->invoice_number, $older->invoice_number]);
    }

    public function test_looking_up_a_plate_returns_that_vehicles_past_visits(): void
    {
        $this->pricedSale([
            'customer_name' => 'Bilal Khan', 'phone' => '03119998888',
            'vehicle_plate' => 'LEB-4477', 'vehicle_model' => 'Honda Civic 2021',
        ]);

        $this->actingAs($this->technician())
            ->get(route('service-history.index', ['q' => 'LEB-4477']))
            ->assertOk()
            ->assertSee('Bilal Khan')
            ->assertSee('LEB-4477')
            ->assertSee('Honda Civic 2021');
    }

    public function test_a_plate_typed_without_its_dash_finds_the_same_vehicle(): void
    {
        $this->pricedSale(['vehicle_plate' => 'LEB-4477', 'customer_name' => 'Bilal Khan']);

        $this->actingAs($this->technician())
            ->get(route('service-history.index', ['q' => 'leb 4477']))
            ->assertOk()
            ->assertSee('Bilal Khan');
    }

    /**
     * Three members of staff type the same number three different ways. All
     * three must land on the same customer.
     */
    public function test_a_phone_typed_with_dashes_spaces_or_a_country_code_finds_the_same_customer(): void
    {
        $this->pricedSale([
            'customer_name' => 'Ali Raza', 'phone' => '03001234567', 'vehicle_plate' => 'AAA-111',
        ]);

        foreach (['03001234567', '0300-123 4567', '+92 300 1234567', '(0300) 1234567'] as $typed) {
            $this->actingAs($this->technician())
                ->get(route('service-history.index', ['q' => $typed]))
                ->assertOk()
                ->assertSee('Ali Raza');
        }
    }

    public function test_a_customer_with_no_history_shows_a_no_previous_visits_state(): void
    {
        $this->actingAs($this->technician())
            ->get(route('service-history.index', ['q' => '03007654321']))
            ->assertOk()
            ->assertSee('No previous visits');
    }

    public function test_another_customers_visits_are_never_returned(): void
    {
        $this->pricedSale([
            'customer_name' => 'Ali Raza', 'phone' => '03001234567', 'vehicle_plate' => 'AAA-111',
        ]);
        $this->pricedSale([
            'customer_name' => 'Sana Malik', 'phone' => '03219876543', 'vehicle_plate' => 'ZZZ-999',
        ]);

        $this->actingAs($this->technician())
            ->get(route('service-history.index', ['q' => '03001234567']))
            ->assertOk()
            ->assertSee('Ali Raza')
            ->assertDontSee('Sana Malik')
            ->assertDontSee('ZZZ-999');
    }

    public function test_the_work_performed_is_listed_for_each_past_visit(): void
    {
        $sale = Sale::factory()->create(['phone' => '03001234567', 'vehicle_plate' => 'AAA-111']);
        SaleItem::factory()->for($sale, 'sale')->create(['item_name' => 'ZIC 10W-40 Full Synthetic']);
        SaleItem::factory()->for($sale, 'sale')->repair()->create(['item_name' => 'Brake Pad Replacement']);
        SaleItem::factory()->for($sale, 'sale')->custom()->create(['item_name' => 'Undercarriage wash']);

        $this->actingAs($this->technician())
            ->get(route('service-history.index', ['q' => '03001234567']))
            ->assertOk()
            ->assertSee('ZIC 10W-40 Full Synthetic')
            ->assertSee('Brake Pad Replacement')
            ->assertSee('Undercarriage wash')
            ->assertSee('Repair');
    }

    /* ---------------------------------------------------------------- */
    /* The critical rule: technicians never see money */
    /* ---------------------------------------------------------------- */

    public function test_a_technicians_lookup_never_contains_any_price_or_total(): void
    {
        $this->pricedSale([
            'customer_name' => 'Ali Raza', 'phone' => '03001234567', 'vehicle_plate' => 'AAA-111',
        ]);

        $response = $this->actingAs($this->technician())
            ->get(route('service-history.index', ['q' => '03001234567']))
            ->assertOk()
            ->assertSee('Ali Raza')
            ->assertSee('ZIC 10W-40 Full Synthetic');

        foreach (['4,242.42', '4242.42', '5,151.51', '5151.51', '1,313.13', '1313.13', '777.77'] as $amount) {
            $response->assertDontSee($amount);
        }

        $response->assertDontSee('Total');
    }

    public function test_an_admins_lookup_does_show_the_amounts(): void
    {
        $this->pricedSale([
            'customer_name' => 'Ali Raza', 'phone' => '03001234567', 'vehicle_plate' => 'AAA-111',
        ]);

        $this->actingAs($this->admin())
            ->get(route('service-history.index', ['q' => '03001234567']))
            ->assertOk()
            ->assertSee('4,242.42')
            ->assertSee('5,151.51')
            ->assertSee('1,313.13')
            ->assertSee('777.77');
    }

    public function test_a_manager_also_sees_the_amounts(): void
    {
        $this->pricedSale([
            'customer_name' => 'Ali Raza', 'phone' => '03001234567', 'vehicle_plate' => 'AAA-111',
        ]);

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('service-history.index', ['q' => '03001234567']))
            ->assertOk()
            ->assertSee('4,242.42');
    }

    /**
     * Belt and braces: the amounts must not merely be hidden in the Blade —
     * they must never be loaded for a user who may not see them.
     */
    public function test_the_amounts_are_not_even_loaded_for_a_technician(): void
    {
        $this->pricedSale(['phone' => '03001234567', 'vehicle_plate' => 'AAA-111']);

        $response = $this->actingAs($this->technician())
            ->get(route('service-history.index', ['q' => '03001234567']))
            ->assertOk();

        $visits = $response->viewData('visits');

        $this->assertCount(1, $visits);
        $this->assertNull($visits->first()->total_amount);
        $this->assertNull($visits->first()->labor_charge);
        $this->assertNull($visits->first()->misc_charge);
        $this->assertNull($visits->first()->lines->first()->manually_charged_price);
    }

    /* ---------------------------------------------------------------- */
    /* Search hygiene */
    /* ---------------------------------------------------------------- */

    public function test_a_percent_search_term_does_not_return_unrelated_records(): void
    {
        $this->pricedSale(['customer_name' => 'Ali Raza', 'phone' => '03001234567', 'vehicle_plate' => 'AAA-111']);
        $this->pricedSale(['customer_name' => 'Sana Malik', 'phone' => '03219876543', 'vehicle_plate' => 'ZZZ-999']);

        $this->actingAs($this->technician())
            ->get(route('service-history.index', ['q' => '%']))
            ->assertOk()
            ->assertSee('No previous visits')
            ->assertDontSee('Ali Raza')
            ->assertDontSee('Sana Malik');
    }

    public function test_an_underscore_search_term_does_not_return_unrelated_records(): void
    {
        $this->pricedSale(['customer_name' => 'Ali Raza', 'phone' => '03001234567', 'vehicle_plate' => 'AAA-111']);

        $this->actingAs($this->technician())
            ->get(route('service-history.index', ['q' => '_']))
            ->assertOk()
            ->assertDontSee('Ali Raza');
    }

    public function test_a_lookup_can_also_be_made_by_customer_name(): void
    {
        $this->pricedSale(['customer_name' => 'Ali Raza', 'phone' => '03001234567', 'vehicle_plate' => 'AAA-111']);

        $this->actingAs($this->technician())
            ->get(route('service-history.index', ['q' => 'Ali Raza']))
            ->assertOk()
            ->assertSee('AAA-111');
    }

    /* ---------------------------------------------------------------- */
    /* Access control */
    /* ---------------------------------------------------------------- */

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('service-history.index'))->assertRedirect(route('login'));
    }

    public function test_the_lookup_404s_when_the_workshop_module_is_switched_off(): void
    {
        app(ModuleRegistry::class)->setEnabled('workshop', false);

        $this->actingAs($this->technician())
            ->get(route('service-history.index'))
            ->assertNotFound();
    }
}
