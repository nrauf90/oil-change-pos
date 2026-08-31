<?php

namespace Tests\Feature;

use App\Models\ModuleSetting;
use App\Models\User;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): ModuleRegistry
    {
        return app(ModuleRegistry::class);
    }

    public function test_every_shipped_module_is_registered(): void
    {
        $keys = $this->registry()->all()->keys()->all();

        foreach (['sales', 'inventory', 'reports', 'expenses', 'scripts', 'workshop', 'admin'] as $expected) {
            $this->assertContains($expected, $keys);
        }
    }

    public function test_modules_start_enabled_on_a_fresh_install(): void
    {
        foreach ($this->registry()->all() as $module) {
            $this->assertTrue(
                $this->registry()->enabled($module->key()),
                "{$module->key()} should be enabled by default"
            );
        }
    }

    public function test_an_optional_module_can_be_switched_off_and_back_on(): void
    {
        $this->registry()->setEnabled('expenses', false);
        $this->assertFalse($this->registry()->enabled('expenses'));

        $this->registry()->setEnabled('expenses', true);
        $this->assertTrue($this->registry()->enabled('expenses'));
    }

    public function test_switching_a_module_off_is_persisted(): void
    {
        $this->registry()->setEnabled('workshop', false);

        $this->assertDatabaseHas('modules', ['key' => 'workshop', 'enabled' => false]);
    }

    public function test_a_core_module_cannot_be_switched_off(): void
    {
        $this->registry()->setEnabled('sales', false);

        $this->assertTrue($this->registry()->enabled('sales'), 'the POS is load-bearing and must stay on');
        $this->assertDatabaseMissing('modules', ['key' => 'sales']);
    }

    public function test_an_unknown_module_key_is_treated_as_disabled(): void
    {
        $this->assertFalse($this->registry()->enabled('does-not-exist'));
    }

    public function test_a_stale_enabled_row_for_an_unknown_module_stays_disabled(): void
    {
        ModuleSetting::create(['key' => 'ghost-feature', 'enabled' => true]);

        $this->assertFalse(
            $this->registry()->enabled('ghost-feature'),
            'a row for a module the code no longer knows about must fail closed'
        );
    }

    public function test_it_reports_which_enabled_modules_depend_on_a_module(): void
    {
        $dependents = $this->registry()->enabledDependents('sales')->keys()->all();

        $this->assertContains('reports', $dependents);
        $this->assertContains('expenses', $dependents);
    }

    public function test_a_disabled_module_contributes_no_dependents(): void
    {
        $this->registry()->setEnabled('expenses', false);

        $this->assertNotContains('expenses', $this->registry()->enabledDependents('sales')->keys()->all());
    }

    public function test_the_permission_catalogue_includes_every_modules_permissions(): void
    {
        $names = $this->registry()->allPermissionNames();

        $this->assertContains('pos.use', $names);
        $this->assertContains('expenses.view_cash_drawer', $names);
        $this->assertContains('inspections.create', $names);
    }

    public function test_permissions_of_a_disabled_module_remain_in_the_catalogue(): void
    {
        $this->registry()->setEnabled('expenses', false);

        $this->assertContains(
            'expenses.view_cash_drawer',
            $this->registry()->allPermissionNames(),
            'switching a module back on must restore its permissions'
        );
    }

    /* ---------------------------------------------------------------- */
    /* Navigation */
    /* ---------------------------------------------------------------- */

    public function test_navigation_hides_entries_the_user_lacks_permission_for(): void
    {
        $technician = User::factory()->technician()->create();

        $labels = collect($this->registry()->navigationFor($technician))->pluck('label')->all();

        $this->assertContains('Scripts', $labels);
        $this->assertNotContains('New Sale', $labels, 'a technician cannot create bills');
        $this->assertNotContains('Dashboard', $labels, 'a technician cannot see financials');
    }

    public function test_navigation_shows_the_counter_entries_to_a_manager(): void
    {
        $labels = collect($this->registry()->navigationFor(User::factory()->manager()->create()))
            ->pluck('label')->all();

        $this->assertContains('New Sale', $labels);
        $this->assertContains('Sales', $labels);
        $this->assertContains('Inventory', $labels);
    }

    public function test_navigation_drops_entries_belonging_to_a_disabled_module(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertContains('Expenses', collect($this->registry()->navigationFor($admin))->pluck('label')->all());

        $this->registry()->setEnabled('expenses', false);

        $this->assertNotContains('Expenses', collect($this->registry()->navigationFor($admin))->pluck('label')->all());
    }

    public function test_navigation_for_a_guest_is_empty(): void
    {
        $this->assertSame([], $this->registry()->navigationFor(null));
    }

    /* ---------------------------------------------------------------- */
    /* Route gating */
    /* ---------------------------------------------------------------- */

    public function test_routes_of_a_disabled_module_return_404(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('scripts.index'))->assertOk();

        $this->registry()->setEnabled('scripts', false);

        $this->get(route('scripts.index'))->assertNotFound();
    }

    public function test_routes_of_a_core_module_stay_reachable(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->registry()->setEnabled('sales', false);

        $this->get(route('sales.index'))->assertOk();
    }

    public function test_every_module_declares_a_key_title_and_description(): void
    {
        foreach ($this->registry()->all() as $module) {
            $this->assertInstanceOf(Module::class, $module);
            $this->assertNotEmpty($module->key());
            $this->assertNotEmpty($module->title());
            $this->assertNotEmpty($module->description());
        }
    }

    public function test_module_dependencies_all_point_at_real_modules(): void
    {
        foreach ($this->registry()->all() as $module) {
            foreach ($module->dependsOn() as $dependency) {
                $this->assertTrue(
                    $this->registry()->has($dependency),
                    "{$module->key()} depends on unknown module {$dependency}"
                );
            }
        }
    }
}
