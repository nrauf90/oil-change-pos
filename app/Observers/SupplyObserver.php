<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Supply;

class SupplyObserver
{
    /**
     * Handle the Supply "created" event.
     */
    public function created(Supply $supply): void
    {
        ActivityLog::record('supply.created', 'Recorded a supplier delivery of '.number_format((float) $supply->total_amount, 2), $supply, [
            'supplier_id' => $supply->supplier_id,
            'total_amount' => $supply->total_amount,
            'received_at' => $supply->received_at?->toDateString(),
            'has_bill_image' => filled($supply->bill_image_path),
        ]);
    }
}
