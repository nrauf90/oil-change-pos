<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Sale;
use RuntimeException;

/**
 * Turns a finished bill into a Sale.
 *
 * This is the audited boundary between operational state and financial record.
 * Everything before it is editable; everything after it is not. Stock moves
 * here and only here, exactly once, because {@see RecordSale} does the moving.
 *
 * The whole transition is one transaction: if the sale cannot be written, the
 * draft is left exactly as it was. A counter that loses a bill on a hiccup is
 * a counter nobody trusts again.
 */
class CompleteOrder
{
    public function __construct(private readonly RecordSale $recordSale) {}

    public function __invoke(Order $order): Sale
    {
        if (! $order->isDraft()) {
            throw new RuntimeException('This bill has already been completed.');
        }

        return $order->getConnection()->transaction(function () use ($order): Sale {
            // Reloaded under a lock so two counter staff pressing Complete at
            // the same instant cannot both write a sale from one bill.
            $locked = $order->newQuery()->lockForUpdate()->find($order->getKey());

            if ($locked === null || ! $locked->isDraft()) {
                throw new RuntimeException('This bill has already been completed.');
            }

            $sale = ($this->recordSale)($this->saleData($locked->load('lines')));

            $locked->forceFill([
                'status' => OrderStatus::Completed,
                'sale_id' => $sale->getKey(),
            ])->save();

            return $sale;
        });
    }

    /**
     * The checkout payload RecordSale already knows how to handle, so the sale
     * a draft produces is identical to one rung up directly at the counter.
     *
     * @return array<string, mixed>
     */
    private function saleData(Order $order): array
    {
        return [
            'customer_name' => $order->customer_name,
            'phone' => $order->phone,
            'vehicle_model' => $order->vehicle_model,
            'vehicle_plate' => $order->vehicle_plate,
            'mileage' => $order->mileage,
            'next_checkup_mileage' => $order->next_checkup_mileage,
            'notes' => $order->notes,
            'labor_charge' => $order->labor_charge,
            'misc_charge' => $order->misc_charge,
            'discount' => $order->discount,
            'lines' => $order->lines->map(fn ($line): array => [
                'item_id' => $line->item_id,
                'item_name' => $line->item_name,
                'type' => $line->type->value,
                'quantity' => $line->quantity,
                'dispensed_quantity' => $line->dispensed_quantity,
                'manually_charged_price' => $line->manually_charged_price,
            ])->all(),
        ];
    }
}
