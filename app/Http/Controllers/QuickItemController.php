<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\QuickItemRequest;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "on-the-fly" inventory endpoints the sale screen calls over fetch.
 *
 * Both return JSON and never redirect: the checkout page must not navigate
 * away mid-bill, or the counter loses everything they have typed.
 */
class QuickItemController extends Controller
{
    /** Keeps the picker fast; the sale screen narrows with ?q= for a big catalogue. */
    private const MAX_RESULTS = 50;

    public function index(Request $request): JsonResponse
    {
        $q = is_string($request->query('q')) ? $request->query('q') : null;
        $type = is_string($request->query('type')) ? $request->query('type') : null;

        $items = Item::query()
            ->active()
            ->ofType($type)
            ->search($q)
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();

        return response()->json([
            'data' => $items->map(fn (Item $item) => $this->payload($item))->values(),
        ]);
    }

    public function store(QuickItemRequest $request): JsonResponse
    {
        // Forced active so the item the counter just created is immediately
        // selectable in the row that asked for it.
        $item = Item::create($request->validated() + ['is_active' => true]);

        return response()->json(['data' => $this->payload($item)], 201);
    }

    /**
     * Everything the front-end needs to drop the item straight onto the tile
     * grid and into the ticket.
     *
     * Delegates to the sale screen's own presenter so a quick-added item is
     * shaped exactly like one that came down with the page — same category,
     * same glyph, same stock label. Anything the tile grid does not need but
     * inventory callers do is merged on top.
     *
     * @return array<string, mixed>
     */
    private function payload(Item $item): array
    {
        return PosController::present($item, $this->maySeeCost()) + [
            'low_stock_alert' => $item->low_stock_alert,
        ];
    }

    private function maySeeCost(): bool
    {
        return request()->user()?->can(Permission::ViewItemUnitCost->value) ?? false;
    }
}
