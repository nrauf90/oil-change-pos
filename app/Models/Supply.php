<?php

namespace App\Models;

use Database\Factories\SupplyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supply extends TenantModel
{
    /** @use HasFactory<SupplyFactory> */
    use HasFactory;

    protected $fillable = [
        'received_at', 'reference_number', 'items_received', 'total_amount', 'bill_image_path', 'notes',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'date', 'total_amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function paidAmount(): string
    {
        return number_format((float) $this->payments->sum('amount'), 2, '.', '');
    }

    public function balanceDue(): string
    {
        return number_format(max(0, (float) $this->total_amount - (float) $this->paidAmount()), 2, '.', '');
    }

    public function isPaid(): bool
    {
        return (float) $this->balanceDue() <= 0;
    }
}
