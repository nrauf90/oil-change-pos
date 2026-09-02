<?php

namespace Database\Seeders;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Models\Item;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class TenantReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->catalogue();
        $this->referenceVehicles();
    }

    private function catalogue(): void
    {
        $items = [...$this->oils(), ...$this->parts(), ...$this->repairs()];

        foreach ($items as $item) {
            Item::firstOrCreate(
                ['name' => $item['name']],
                [...Arr::except($item, ['name']), 'is_active' => true],
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function oils(): array
    {
        $carton = [
            'type' => ItemType::Product,
            'unit_of_measure' => UnitOfMeasure::Litre,
            'pack_label' => 'Carton',
            'units_per_pack' => 4,
            'measure_per_unit' => 4,
        ];
        $oils = [
            ['ZIC X7 10W-40 Full Synthetic (4L)', 6800, 48, 16],
            ['ZIC X5 10W-40 Semi-Synthetic (4L)', 4200, 32, 16],
            ['Shell Helix HX7 5W-30 (4L)', 7200, 28, 16],
            ['Toyota Genuine 5W-30 (4L)', 8100, 16, 16],
            ['Conventional 20W-50 Mineral (4L)', 2600, 12, 16],
        ];

        return array_map(fn (array $oil): array => [
            'name' => $oil[0],
            'unit_cost' => $oil[1],
            'stock_level' => $oil[2],
            'low_stock_alert' => $oil[3],
            ...$carton,
        ], $oils);
    }

    /** @return array<int, array<string, mixed>> */
    private function parts(): array
    {
        $parts = [
            ['Oil Filter — Toyota Corolla', 850, 24, 6],
            ['Oil Filter — Honda Civic', 900, 18, 6],
            ['Oil Filter — Suzuki Alto', 620, 5, 6],
            ['Air Filter — Universal', 1100, 14, 4],
            ['Cabin / AC Filter', 1350, 9, 4],
            ['Wiper Blade Pair', 1800, 7, 3],
            ['Coolant / Antifreeze (1L)', 950, 20, 6],
            ['Brake Fluid DOT 4 (500ml)', 780, 15, 4],
            ['Battery Terminal Cleaner', 350, 11, 3],
            ['Gear Oil 80W-90 (1L)', 1150, 10, 4],
        ];

        return array_map(fn (array $part): array => [
            'name' => $part[0],
            'type' => ItemType::Product,
            'unit_of_measure' => UnitOfMeasure::Piece,
            'unit_cost' => $part[1],
            'stock_level' => $part[2],
            'low_stock_alert' => $part[3],
        ], $parts);
    }

    /** @return array<int, array<string, mixed>> */
    private function repairs(): array
    {
        $repairs = [
            ['Oil & Filter Change — Labour', 500],
            ['Brake Pad Replacement (Front)', 2500],
            ['Brake Pad Replacement (Rear)', 2800],
            ['Brake Disc Skimming', 3200],
            ['Suspension Tuning', 4500],
            ['Engine Tuning', 5500],
            ['Wheel Alignment & Balancing', 2200],
            ['Battery Check & Terminal Service', 600],
            ['Coolant Flush', 1800],
            ['Multi-Point Inspection', 0],
        ];
        $rows = array_map(fn (array $repair): array => [
            'name' => $repair[0],
            'type' => ItemType::Repair,
            'unit_cost' => $repair[1],
        ], $repairs);
        $rows[] = [
            'name' => 'AC Gas Refill',
            'type' => ItemType::Repair,
            'unit_of_measure' => UnitOfMeasure::Kilogram,
            'unit_cost' => 3500,
            'pack_label' => 'Cylinder',
            'units_per_pack' => 1,
            'measure_per_unit' => 13,
            'stock_level' => 13,
            'low_stock_alert' => 3,
        ];

        return $rows;
    }

    private function referenceVehicles(): void
    {
        $makes = [
            'Daihatsu' => ['Cuore', 'Hijet', 'Mira'],
            'FAW' => ['V2', 'XPV'],
            'Honda' => ['BR-V', 'City', 'Civic', 'HR-V'],
            'Hyundai' => ['Elantra', 'Tucson'],
            'Kia' => ['Picanto', 'Sportage'],
            'Mitsubishi' => ['Lancer', 'Pajero'],
            'Nissan' => ['Dayz', 'Sunny'],
            'Suzuki' => ['Alto', 'Bolan', 'Cultus', 'Mehran', 'Ravi', 'Swift', 'Wagon R'],
            'Toyota' => ['Aqua', 'Corolla', 'Hilux', 'Prado', 'Vitz', 'Yaris'],
        ];

        foreach ($makes as $makeName => $modelNames) {
            $make = VehicleMake::firstOrCreate(['name' => $makeName]);

            foreach ($modelNames as $modelName) {
                VehicleModel::firstOrCreate([
                    'vehicle_make_id' => $make->id,
                    'name' => $modelName,
                ]);
            }
        }
    }
}
