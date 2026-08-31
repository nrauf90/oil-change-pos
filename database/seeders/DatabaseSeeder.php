<?php

namespace Database\Seeders;

use App\Enums\ItemType;
use App\Enums\Role;
use App\Enums\UnitOfMeasure;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->staff();
        $this->catalogue();
    }

    /**
     * One account per role so the shop can sign in the moment it is installed.
     * Change these passwords before putting the terminal on a network.
     */
    private function staff(): void
    {
        $accounts = [
            [Role::Admin, 'owner', 'Shop Owner'],
            [Role::Manager, 'counter', 'Front Desk'],
            [Role::Technician, 'mechanic', 'Workshop Technician'],
        ];

        foreach ($accounts as [$role, $username, $name]) {
            $user = User::firstOrCreate(
                ['username' => $username],
                ['name' => $name, 'password' => Hash::make('password'), 'is_active' => true],
            );

            $user->assignRoleEnum($role);
        }
    }

    /**
     * A starter catalogue. Every `unit_cost` here is a reference figure only —
     * the counter still types the real price on every single bill.
     *
     * Stock is seeded the way the shop actually holds it, so a fresh install can
     * demonstrate the consumption report without anyone reconfiguring an item
     * first: engine oil is bought by the carton and poured by the litre, AC gas
     * arrives as a 13 kg cylinder and goes out a kilo or two at a time, and
     * filters sit on a shelf as countable pieces with a threshold to fall below.
     *
     * Idempotent — the name is the key, so re-running never duplicates a row and
     * never overwrites a level the shop has since corrected by hand.
     */
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

    /**
     * Engine oil: measured in litres, bought in cartons of four 4-litre bottles,
     * so one carton is 16 L and the owner reorders in cartons.
     *
     * @return array<int, array<string, mixed>>
     */
    private function oils(): array
    {
        $carton = [
            'type' => ItemType::Product,
            'unit_of_measure' => UnitOfMeasure::Litre,
            'pack_label' => 'Carton',
            'units_per_pack' => 4,
            'measure_per_unit' => 4,
        ];

        // [name, unit cost, opening litres, reorder at]
        $oils = [
            ['ZIC X7 10W-40 Full Synthetic (4L)', 6800, 48, 16],
            ['ZIC X5 10W-40 Semi-Synthetic (4L)', 4200, 32, 16],
            ['Shell Helix HX7 5W-30 (4L)', 7200, 28, 16],
            ['Toyota Genuine 5W-30 (4L)', 8100, 16, 16],
            ['Conventional 20W-50 Mineral (4L)', 2600, 12, 16],
        ];

        return array_map(fn (array $oil) => [
            'name' => $oil[0],
            'unit_cost' => $oil[1],
            'stock_level' => $oil[2],
            'low_stock_alert' => $oil[3],
            ...$carton,
        ], $oils);
    }

    /**
     * Shelf stock counted in whole pieces — a filter is a filter, and nobody
     * stocks a third of one. Every line carries a threshold so the low-stock
     * badge has something to show on day one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parts(): array
    {
        // [name, unit cost, opening pieces, reorder at]
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

        return array_map(fn (array $part) => [
            'name' => $part[0],
            'type' => ItemType::Product,
            'unit_of_measure' => UnitOfMeasure::Piece,
            'unit_cost' => $part[1],
            'stock_level' => $part[2],
            'low_stock_alert' => $part[3],
        ], $parts);
    }

    /**
     * Labour tasks hold no stock — except AC gas, which is a repair the shop
     * genuinely buys by weight. A measured repair is legitimate: the cylinder is
     * consumable stock even though the line on the bill is a service.
     *
     * @return array<int, array<string, mixed>>
     */
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

        $rows = array_map(fn (array $repair) => [
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
}
