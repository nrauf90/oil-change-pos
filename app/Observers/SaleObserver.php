<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Sale;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Str;
use Throwable;

/**
 * The shop's record of trade, and of anything that erases it.
 *
 * The trail hangs off the model events rather than the controller so every
 * write path is covered. `ShouldHandleEventsAfterCommit` matters here: a sale
 * is saved inside RecordSale's transaction, and an audit write that ran inside
 * it could roll a completed sale back. Deferring to after the commit means the
 * money is banked before this code runs at all — and it is also the only point
 * at which the sale's lines exist to be counted.
 */
class SaleObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Sale $sale): void
    {
        // Second belt on top of the after-commit brace: the sale is already
        // committed, so a failure here can no longer undo it, but an uncaught
        // throw would still turn a successful checkout into a 500 and send the
        // counter staff back to re-key the whole bill. A missing audit line is
        // a problem for the morning; a lost sale is a problem right now.
        try {
            $lines = $sale->lines()->count();

            ActivityLog::record(
                action: 'sale.created',
                description: sprintf(
                    'Recorded sale %s for %s — %d %s, total %s',
                    $sale->invoice_number,
                    $sale->customer_name ?: 'walk-in customer',
                    $lines,
                    Str::plural('line', $lines),
                    number_format((float) $sale->total_amount, 2),
                ),
                subject: $sale,
                properties: [
                    'invoice_number' => $sale->invoice_number,
                    'customer_name' => $sale->customer_name,
                    'vehicle_plate' => $sale->vehicle_plate,
                    // The cast keeps this a two-decimal string, so the amount
                    // that was actually charged is what lands in the log.
                    'total_amount' => $sale->total_amount,
                    'labor_charge' => $sale->labor_charge,
                    'misc_charge' => $sale->misc_charge,
                    'discount' => $sale->discount,
                    'line_count' => $lines,
                    'cashier_id' => $sale->cashier_id,
                    'cashier_name' => $sale->cashier?->name,
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Deleting a sale destroys a financial record — the loudest entry there is. */
    public function deleted(Sale $sale): void
    {
        ActivityLog::record(
            action: 'sale.deleted',
            description: sprintf(
                'Deleted sale %s for %s, total %s',
                $sale->invoice_number,
                $sale->customer_name ?: 'walk-in customer',
                number_format((float) $sale->total_amount, 2),
            ),
            subject: $sale,
            properties: [
                'invoice_number' => $sale->invoice_number,
                'customer_name' => $sale->customer_name,
                'phone' => $sale->phone,
                'vehicle_plate' => $sale->vehicle_plate,
                'vehicle_model' => $sale->vehicle_model,
                'total_amount' => $sale->total_amount,
                'labor_charge' => $sale->labor_charge,
                'misc_charge' => $sale->misc_charge,
                'discount' => $sale->discount,
                'cashier_id' => $sale->cashier_id,
                'sold_at' => $sale->created_at?->toDateTimeString(),
            ],
        );
    }
}
