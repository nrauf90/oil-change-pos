<?php

namespace App\Actions;

use App\Enums\ItemType;
use App\Models\Item;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Facades\DB;

/**
 * Delete a bill and put back what it took off the shelf.
 *
 * The mirror of {@see RecordSale}: deleting an invoice means it never
 * happened, so the units it drew are returned. Without this the shelf drifted
 * downward every time a mis-keyed bill was removed, and the low-stock alert
 * fired against stock that was still physically there.
 *
 * The return rule mirrors RecordSale::stockDraw() exactly — measured items
 * return what was dispensed, pieces return their quantity, repairs return
 * nothing because they never drew anything.
 */
final class DeleteSale
{
    public function handle(Sale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            $this->returnStock($sale);
            $sale->delete();
        });
    }

    private function returnStock(Sale $sale): void
    {
        $lines = $sale->lines()
            ->whereNotNull('item_id')
            ->get(['id', 'sale_id', 'item_id', 'quantity', 'dispensed_quantity', 'type']);

        if ($lines->isEmpty()) {
            return;
        }

        $items = Item::query()
            ->whereIn('id', $lines->pluck('item_id')->unique()->all())
            ->get(['id', 'type', 'unit_of_measure', 'stock_level'])
            ->keyBy('id');

        $amounts = [];

        foreach ($lines as $line) {
            $amount = $this->stockReturn($items->get($line->item_id), $line);

            if ($amount <= 0.0) {
                continue;
            }

            $amounts[$line->item_id] = ($amounts[$line->item_id] ?? 0.0) + $amount;
        }

        foreach ($amounts as $itemId => $amount) {
            Item::whereKey($itemId)
                ->whereNotNull('stock_level')
                ->increment('stock_level', $amount);
        }
    }

    /** How much this line puts back on the shelf. */
    private function stockReturn(?Item $item, SaleItem $line): float
    {
        if ($item === null || $item->stock_level === null) {
            return 0.0;
        }

        if ($item->isMeasured()) {
            return (float) ($line->dispensed_quantity ?? 0);
        }

        return $item->type === ItemType::Product ? (float) $line->quantity : 0.0;
    }
}
