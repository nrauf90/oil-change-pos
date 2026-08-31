<?php

namespace App\Http\Controllers;

use App\Enums\ItemType;
use App\Enums\Permission;
use App\Enums\UnitOfMeasure;
use App\Models\CustomerVehicle;
use App\Models\Item;
use App\Support\ServiceHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PosController extends Controller
{
    /**
     * The counter's category rail.
     *
     * The shop has no user-defined categories, so the rail is derived from the
     * two facts inventory already records — what kind of thing it is, and how
     * it is measured. That is exactly how an oil-change counter thinks about
     * its shelves: oils are poured, gas is weighed, parts are picked, work is
     * done. Each entry carries its own colour and glyph so a tile is
     * recognisable before the name is read.
     *
     * @var array<int, array{key: string, label: string, glyph: string, accent: string}>
     */
    private const GROUPS = [
        ['key' => 'oil', 'label' => 'Oils & fluids', 'glyph' => '🛢', 'accent' => 'amber'],
        ['key' => 'gas', 'label' => 'AC gas', 'glyph' => '❄', 'accent' => 'sky'],
        ['key' => 'part', 'label' => 'Parts', 'glyph' => '⚙', 'accent' => 'emerald'],
        ['key' => 'service', 'label' => 'Services', 'glyph' => '🔧', 'accent' => 'violet'],
    ];

    public function create(): View
    {
        $showCost = request()->user()?->can(Permission::ViewItemUnitCost->value) ?? false;

        return view('pos.create', [
            // Seeded into Alpine so the first render of the picker needs no round trip.
            // The quick-add modal appends to this list in place, without a reload.
            'items' => Item::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'unit_cost', 'unit_of_measure', 'stock_level', 'low_stock_alert'])
                ->map(fn (Item $item) => self::present($item, $showCost))
                ->values(),
            'groups' => self::GROUPS,
            'types' => ItemType::cases(),
            'customerVehicles' => CustomerVehicle::query()
                ->latest('updated_at')
                ->latest('id')
                ->limit(10)
                ->get(['id', 'customer_name', 'phone', 'vehicle_model', 'vehicle_plate', 'mileage']),
        ]);
    }

    public function customerVehicles(Request $request): JsonResponse
    {
        $search = trim($request->string('q')->toString());

        if (mb_strlen($search) < 3) {
            return response()->json([]);
        }

        $like = '%'.ServiceHistory::escapeLike($search).'%';

        $profiles = CustomerVehicle::query()
            ->where(function (Builder $query) use ($like): void {
                $grammar = $query->getQuery()->getGrammar();

                foreach (['customer_name', 'phone', 'vehicle_plate', 'vehicle_model'] as $column) {
                    $query->orWhereRaw($grammar->wrap($column).' like ? escape ?', [$like, '\\']);
                }
            })
            ->latest('updated_at')
            ->latest('id')
            ->get(['id', 'customer_name', 'phone', 'vehicle_model', 'vehicle_plate', 'mileage']);

        return response()->json($profiles);
    }

    /**
     * One item as the sale screen's tile grid needs it.
     *
     * Kept public and static because the quick-add endpoint has to hand back a
     * payload the tile grid can render without a page reload — the two must
     * never drift apart.
     *
     * @return array<string, mixed>
     */
    public static function present(Item $item, bool $showCost): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'type' => $item->type->value,
            'type_label' => $item->type->label(),
            // Drives the litres/kg box on the cart row. A piece item gets none.
            'unit_of_measure' => $item->unit_of_measure->value,
            'unit_abbreviation' => $item->unit_of_measure->abbreviation(),
            'is_measured' => $item->isMeasured(),
            // Owner-only. A manager's browser never receives the figure at
            // all, so it cannot leak through the page source either.
            ...($showCost ? ['unit_cost' => $item->unit_cost] : []),
            'stock_level' => $item->stock_level,
            'stock_label' => $item->stockLabel(),
            'is_low_on_stock' => $item->isLowOnStock(),
            // Which shelf of the category rail this tile sits on.
            'group' => self::groupFor($item),
            'glyph' => self::glyphFor($item),
            // Pre-lowercased so filtering a 500-item catalogue on every
            // keystroke never re-walks the strings.
            'haystack' => mb_strtolower($item->name),
        ];
    }

    /**
     * How a thing is measured decides its shelf before what kind of thing it is
     * does. An AC gas refill is billed as labour but is drawn by the kilo out of
     * a cylinder, so it belongs on the AC gas shelf beside the other things the
     * counter pours — not filed among the wrench-and-spanner jobs, where nobody
     * weighing out gas would think to look for it.
     *
     * Reading the type first is what left that shelf permanently empty: a
     * kilogram item is the only thing that can sit on it, and every one the shop
     * has is a repair.
     */
    private static function groupFor(Item $item): string
    {
        return match (true) {
            $item->unit_of_measure === UnitOfMeasure::Litre => 'oil',
            $item->unit_of_measure === UnitOfMeasure::Kilogram => 'gas',
            $item->type === ItemType::Repair => 'service',
            default => 'part',
        };
    }

    private static function glyphFor(Item $item): string
    {
        $group = self::groupFor($item);

        foreach (self::GROUPS as $entry) {
            if ($entry['key'] === $group) {
                return $entry['glyph'];
            }
        }

        return '📦';
    }
}
