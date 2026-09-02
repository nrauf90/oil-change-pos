<?php

namespace App\Actions;

use App\Enums\ItemType;
use App\Enums\SaleLineType;
use App\Models\CustomerVehicle;
use App\Models\Item;
use App\Models\Sale;
use App\Support\SaleTotalCalculator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Turns a validated checkout payload into a persisted Sale.
 *
 * Two things this deliberately does NOT do:
 *  - it never reads Item::$unit_cost, so no background rate can leak into a bill;
 *  - it never trusts a client-supplied total or invoice number, both are derived here.
 */
class RecordSale
{
    /** @param array<string, mixed> $data */
    public function __invoke(array $data): Sale
    {
        $lines = $this->resolveLines($data['lines'] ?? []);

        return (new Sale)->getConnection()->transaction(function () use ($data, $lines): Sale {
            $sale = new Sale([
                'customer_name' => $data['customer_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'vehicle_model' => $data['vehicle_model'] ?? null,
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'mileage' => $data['mileage'] ?? null,
                'next_checkup_mileage' => $data['next_checkup_mileage'] ?? null,
                'notes' => $data['notes'] ?? null,
                'labor_charge' => SaleTotalCalculator::amount($data['labor_charge'] ?? null),
                'misc_charge' => SaleTotalCalculator::amount($data['misc_charge'] ?? null),
            ]);

            // Not mass-assignable on purpose: attribution comes from the session,
            // the total is derived here and the invoice number in the model's
            // creating hook, so none of the three can be smuggled in on the request.
            $sale->cashier()->associate(auth()->user());

            $sale->total_amount = SaleTotalCalculator::total(
                array_column($lines, 'manually_charged_price'),
                $data['labor_charge'] ?? null,
                $data['misc_charge'] ?? null,
            );

            $sale->save();

            $this->saveCustomerVehicle($data);

            $sale->lines()->createMany(array_map(
                fn (array $line) => Arr::except($line, 'stock_draw'),
                $lines,
            ));

            $this->deductStock($lines);

            return $sale->load('lines');
        });
    }

    /**
     * Keep reusable counter details separate from the immutable invoice snapshot.
     * A plate identifies a vehicle first; customer details are the fallback for
     * visits where the counter has not recorded a plate yet.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveCustomerVehicle(array $data): void
    {
        $details = collect([
            'customer_name' => $this->blankToNull($data['customer_name'] ?? null),
            'phone' => $this->blankToNull($data['phone'] ?? null),
            'vehicle_model' => $this->blankToNull($data['vehicle_model'] ?? null),
            'vehicle_plate' => $this->blankToNull($data['vehicle_plate'] ?? null),
            'mileage' => $this->blankToNull($data['mileage'] ?? null),
        ])->filter(fn (mixed $value): bool => $value !== null)->all();

        if ($details === []) {
            return;
        }

        $profile = match (true) {
            isset($details['vehicle_plate']) => CustomerVehicle::where('vehicle_plate', $details['vehicle_plate'])->first(),
            isset($details['phone']) => CustomerVehicle::where('phone', $details['phone'])->latest('id')->first(),
            isset($details['customer_name']) => CustomerVehicle::where('customer_name', $details['customer_name'])->latest('id')->first(),
            default => null,
        };

        ($profile ?? new CustomerVehicle)->fill($details)->save();
    }

    /**
     * PRD §2 — closing a sale takes the sold units off the shelf.
     *
     * One relative UPDATE per distinct item, so two counters checking out the
     * same oil at the same instant both land: there is no read-modify-write to
     * lose. The WHERE clause is what decides eligibility, so Repairs, untracked
     * items and pure-custom lines simply match nothing and move no stock.
     *
     * Overselling is allowed on purpose. The part is already in the customer's
     * hands; a drifted count must never stop the shop billing them. A negative
     * balance is the signal that someone needs to recount the shelf.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function deductStock(array $lines): void
    {
        $amounts = [];

        foreach ($lines as $line) {
            // Pure-custom lines arrive here with item_id already nulled out.
            if ($line['item_id'] === null) {
                continue;
            }

            $drawn = $line['stock_draw'];

            if ($drawn === 0.0) {
                continue;
            }

            $amounts[$line['item_id']] = ($amounts[$line['item_id']] ?? 0.0) + $drawn;
        }

        foreach ($amounts as $itemId => $amount) {
            Item::whereKey($itemId)
                ->whereNotNull('stock_level')
                ->decrement('stock_level', $amount);
        }
    }

    /**
     * How much this line takes off the shelf.
     *
     * Bulk consumables are poured, so only the dispensed figure counts, and it
     * counts whatever the item's type — an AC gas refill is labour that empties
     * a cylinder. A measured line with nothing typed moves nothing rather than
     * guessing at it.
     *
     * Everything else goes out in whole pieces, and only a Product has a shelf
     * to come off: a repair is labour, so it moves no stock.
     *
     * @param  array<string, mixed>  $line
     */
    private function stockDraw(?Item $item, array $line): float
    {
        if ($item === null || $item->stock_level === null) {
            return 0.0;
        }

        if ($item->isMeasured()) {
            $dispensed = $this->blankToNull($line['dispensed_quantity'] ?? null);

            return $dispensed === null ? 0.0 : (float) $dispensed;
        }

        return $item->type === ItemType::Product ? (float) ($line['quantity'] ?? 1) : 0.0;
    }

    private function blankToNull(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawLines
     * @return array<int, array<string, mixed>>
     */
    private function resolveLines(array $rawLines): array
    {
        $itemIds = array_filter(array_column($rawLines, 'item_id'));

        /** @var Collection<int, Item> $items */
        $items = $itemIds === []
            ? collect()
            : Item::whereIn('id', $itemIds)->get()->keyBy('id');

        $lines = [];

        foreach ($rawLines as $line) {
            $type = SaleLineType::from($line['type']);

            // A custom line is free text by definition — it is never linked to inventory,
            // even if the browser happened to send an item_id along with it.
            $item = $type === SaleLineType::Custom ? null : $items->get($line['item_id'] ?? null);

            $lines[] = [
                'item_id' => $item?->id,
                // Trust the catalogue for the name when one is linked, so a tampered
                // or stale form field cannot rewrite what the invoice says was sold.
                'item_name' => $item?->name ?? trim((string) $line['item_name']),
                'type' => $type,
                'quantity' => (int) ($line['quantity'] ?? 1),
                // How much bulk stock this line drew (2.5 L of oil, 1.5 kg of gas).
                // A stock record only — it never reaches the printed invoice.
                'dispensed_quantity' => $item?->isMeasured()
                    ? $this->blankToNull($line['dispensed_quantity'] ?? null)
                    : null,
                'stock_draw' => $this->stockDraw($item, $line),
                // The total for this line, exactly as typed. Quantity is deliberately
                // NOT multiplied in — the counter typed the money, not a unit rate.
                'manually_charged_price' => SaleTotalCalculator::amount($line['manually_charged_price']),
            ];
        }

        return $lines;
    }
}
