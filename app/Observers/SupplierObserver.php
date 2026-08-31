<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Supplier;

class SupplierObserver
{
    public function created(Supplier $supplier): void
    {
        ActivityLog::record('supplier.created', "Added supplier {$supplier->name}", $supplier, [
            'name' => $supplier->name,
            'contact_person' => $supplier->contact_person,
        ]);
    }

    /**
     * Handle the Supplier "updated" event.
     */
    public function updated(Supplier $supplier): void
    {
        $changes = ActivityLog::changes($supplier);

        if ($changes !== []) {
            ActivityLog::record('supplier.updated', "Updated supplier {$supplier->name}", $supplier, ['changed' => $changes]);
        }
    }

    /**
     * Handle the Supplier "deleted" event.
     */
    public function deleted(Supplier $supplier): void
    {
        ActivityLog::record('supplier.deleted', "Deleted supplier {$supplier->name}", $supplier, ['name' => $supplier->name]);
    }
}
