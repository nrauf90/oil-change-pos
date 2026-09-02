<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\VehicleMakes\Pages\CreateVehicleMake;
use App\Filament\Resources\VehicleMakes\Pages\EditVehicleMake;
use App\Filament\Resources\VehicleMakes\Pages\ListVehicleMakes;
use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use App\Models\Item;
use App\Models\User;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class VehicleCatalogueManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_managers_cannot_manage_the_shared_vehicle_catalogue(): void
    {
        $manager = User::factory()->manager()->create();
        $make = VehicleMake::factory()->create();

        $this->actingAs($manager);

        $this->assertFalse(VehicleMakeResource::canViewAny());
        $this->assertFalse(VehicleMakeResource::canCreate());
        $this->assertFalse(VehicleMakeResource::canEdit($make));
    }

    /**
     * Vehicle makes and models live in one admin flow so the owner can keep the
     * catalogue tidy without hunting through separate resources.
     */
    public function test_the_owner_can_create_a_vehicle_make_with_multiple_models_from_the_admin_panel(): void
    {
        $restoreRepeaterUuids = Repeater::fake();

        try {
            Livewire::actingAs(User::factory()->admin()->create())
                ->test(CreateVehicleMake::class)
                ->fillForm([
                    'name' => 'Toyota',
                    'vehicleModels' => [
                        ['name' => 'Corolla'],
                        ['name' => 'Yaris'],
                    ],
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        } finally {
            $restoreRepeaterUuids();
        }

        $make = VehicleMake::query()->where('name', 'Toyota')->sole();

        $this->assertSame(
            ['Corolla', 'Yaris'],
            $make->vehicleModels()->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_the_owner_can_rename_a_vehicle_make_and_replace_its_models_from_the_admin_panel(): void
    {
        $restoreRepeaterUuids = Repeater::fake();
        $make = VehicleMake::factory()->create(['name' => 'Honda']);
        $civic = $make->vehicleModels()->create(['name' => 'Civic']);
        $city = $make->vehicleModels()->create(['name' => 'City']);

        try {
            Livewire::actingAs(User::factory()->admin()->create())
                ->test(EditVehicleMake::class, ['record' => $make->getKey()])
                ->fillForm([
                    'name' => 'Honda Atlas',
                    'vehicleModels' => [
                        [
                            'id' => $civic->getKey(),
                            'name' => 'Civic RS',
                        ],
                        [
                            'name' => 'BR-V',
                        ],
                    ],
                ])
                ->call('save')
                ->assertHasNoFormErrors();
        } finally {
            $restoreRepeaterUuids();
        }

        $make->refresh();

        $this->assertSame('Honda Atlas', $make->name);
        $this->assertSame(
            ['BR-V', 'Civic RS'],
            $make->vehicleModels()->orderBy('name')->pluck('name')->all(),
        );
        $this->assertDatabaseMissing('vehicle_models', [
            'id' => $city->getKey(),
        ], 'tenant');
    }

    public function test_the_vehicle_catalogue_table_can_be_searched_by_make_or_model_name(): void
    {
        $toyota = VehicleMake::factory()->create(['name' => 'Toyota']);
        $toyota->vehicleModels()->create(['name' => 'Corolla']);
        $honda = VehicleMake::factory()->create(['name' => 'Honda']);
        $honda->vehicleModels()->create(['name' => 'Civic']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListVehicleMakes::class)
            ->searchTable('Corolla')
            ->assertCanSeeTableRecords([$toyota])
            ->assertCanNotSeeTableRecords([$honda]);
    }

    public function test_the_owner_can_delete_an_unused_vehicle_make_from_the_admin_panel(): void
    {
        $unusedMake = VehicleMake::factory()->create(['name' => 'Suzuki']);
        $unusedMake->vehicleModels()->create(['name' => 'Cultus']);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListVehicleMakes::class)
            ->callTableAction('delete', $unusedMake)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertDatabaseMissing('vehicle_makes', ['id' => $unusedMake->getKey()], 'tenant');
        $this->assertDatabaseMissing('vehicle_models', ['vehicle_make_id' => $unusedMake->getKey()], 'tenant');
    }

    public function test_deleting_a_linked_vehicle_make_warns_about_affected_products_and_removes_only_compatibility_assignments(): void
    {
        $make = VehicleMake::factory()->create(['name' => 'Toyota']);
        $corolla = $make->vehicleModels()->create(['name' => 'Corolla']);
        $yaris = $make->vehicleModels()->create(['name' => 'Yaris']);
        $linkedFilter = Item::factory()->create(['name' => 'Linked Filter', 'is_universal' => false]);
        $spareFilter = Item::factory()->create(['name' => 'Spare Filter', 'is_universal' => false]);
        $linkedFilter->vehicleCompatibilities()->create([
            'vehicle_model_id' => $corolla->getKey(),
            'year_from' => 2009,
            'year_to' => 2013,
        ]);
        $spareFilter->vehicleCompatibilities()->create([
            'vehicle_model_id' => $yaris->getKey(),
            'year_from' => 2011,
            'year_to' => null,
        ]);

        $page = Livewire::actingAs(User::factory()->admin()->create())
            ->test(ListVehicleMakes::class)
            ->mountTableAction('delete', $make)
            ->assertMountedActionModalSee([
                'Linked Filter',
                'Spare Filter',
                'Compatibility assignments for these products will be removed.',
                'The products themselves will stay in inventory.',
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertDatabaseMissing('vehicle_makes', ['id' => $make->getKey()], 'tenant');
        $this->assertDatabaseMissing('vehicle_models', ['id' => $corolla->getKey()], 'tenant');
        $this->assertDatabaseMissing('vehicle_models', ['id' => $yaris->getKey()], 'tenant');
        $this->assertSame(0, $linkedFilter->fresh()->vehicleCompatibilities()->count());
        $this->assertSame(0, $spareFilter->fresh()->vehicleCompatibilities()->count());
        $page->assertCanSeeTableRecords([]);
    }

    public function test_deleting_a_linked_vehicle_model_warns_about_affected_products_and_removes_only_compatibility_assignments(): void
    {
        $restoreRepeaterUuids = Repeater::fake();
        $make = VehicleMake::factory()->create(['name' => 'Honda']);
        $linkedModel = VehicleModel::factory()->for($make)->create(['name' => 'Civic']);
        $unusedModel = VehicleModel::factory()->for($make)->create(['name' => 'City']);
        $linkedFilter = Item::factory()->create(['name' => 'Linked Filter', 'is_universal' => false]);
        $linkedFilter->vehicleCompatibilities()->create([
            'vehicle_model_id' => $linkedModel->getKey(),
            'year_from' => 2014,
            'year_to' => 2018,
        ]);

        try {
            $page = Livewire::actingAs(User::factory()->admin()->create())
                ->test(EditVehicleMake::class, ['record' => $make->getKey()]);

            $linkedRowKey = $this->vehicleModelRepeaterRowKey($page, $linkedModel->getKey());

            $page
                ->mountFormComponentAction('vehicleModels', 'delete', ['item' => $linkedRowKey])
                ->assertMountedActionModalSee([
                    'Linked Filter',
                    'Compatibility assignments for these products will be removed.',
                    'The products themselves will stay in inventory.',
                ])
                ->callMountedFormComponentAction()
                ->call('save')
                ->assertHasNoFormErrors();
        } finally {
            $restoreRepeaterUuids();
        }

        $make->refresh();

        $this->assertSame('Honda', $make->name);
        $this->assertSame(['City'], $make->vehicleModels()->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseMissing('vehicle_models', ['id' => $linkedModel->getKey()], 'tenant');
        $this->assertSame(0, $linkedFilter->fresh()->vehicleCompatibilities()->count());
        $this->assertDatabaseHas('items', ['id' => $linkedFilter->getKey()], 'tenant');
    }

    private function vehicleModelRepeaterRowKey(Testable $page, int $vehicleModelId): string
    {
        /** @var array<string, array{id?: int, name?: string}> $rows */
        $rows = $page->instance()->data['vehicleModels'] ?? [];

        $rowKey = collect($rows)->search(
            fn (array $row): bool => (int) ($row['id'] ?? 0) === $vehicleModelId,
        );

        $this->assertIsString($rowKey);

        return $rowKey;
    }
}
