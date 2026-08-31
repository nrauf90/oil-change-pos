<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Inspection;

/**
 * A condition report is the shop's answer to "you never told me the brakes were
 * gone", so who wrote one and when belongs on the record.
 *
 * Only `created`: the points themselves are written in a second step and are
 * read back from the inspection, which is still there to be read.
 */
class InspectionObserver
{
    public function created(Inspection $inspection): void
    {
        ActivityLog::record(
            action: 'inspection.created',
            description: sprintf(
                'Logged an inspection for %s (%s)',
                $inspection->vehicle_plate,
                $inspection->customer_name ?: 'walk-in customer',
            ),
            subject: $inspection,
            properties: [
                'vehicle_plate' => $inspection->vehicle_plate,
                'vehicle_model' => $inspection->vehicle_model,
                'customer_name' => $inspection->customer_name,
                'mileage' => $inspection->mileage,
                'sale_id' => $inspection->sale_id,
                'inspected_by' => $inspection->inspected_by,
            ],
        );
    }
}
