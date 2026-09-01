<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Database\Factories\SupplierPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupplierPayment extends TenantModel
{
    /** @use HasFactory<SupplierPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'amount', 'method', 'paid_at', 'reference_number', 'receipt_image_path', 'notes',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'method' => PaymentMethod::class, 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<Supply, $this> */
    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasOne<Expense, $this> */
    public function expense(): HasOne
    {
        return $this->hasOne(Expense::class);
    }
}
