<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\SupplierPayment;

class SupplierPaymentObserver
{
    /**
     * Handle the SupplierPayment "created" event.
     */
    public function created(SupplierPayment $supplierPayment): void
    {
        ActivityLog::record('supplier_payment.created', 'Recorded supplier payment of '.number_format((float) $supplierPayment->amount, 2), $supplierPayment, [
            'supply_id' => $supplierPayment->supply_id,
            'amount' => $supplierPayment->amount,
            'method' => $supplierPayment->method?->value,
            'paid_at' => $supplierPayment->paid_at?->toDateTimeString(),
            'has_receipt_image' => filled($supplierPayment->receipt_image_path),
        ]);
    }
}
