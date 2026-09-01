<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Expense extends TenantModel
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    /**
     * `user_id` is deliberately absent: who logged an outlay is decided by the
     * session, never by the request payload, so it is set through the
     * relationship and can never be mass-assigned from a forged form field.
     */
    protected $fillable = ['category', 'payment_method', 'amount', 'description', 'spent_at'];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'payment_method' => PaymentMethod::class,
            'amount' => 'decimal:2',
            'spent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Expense $expense): void {
            $expense->spent_at ??= Carbon::now();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<SupplierPayment, $this> */
    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    /** @param Builder<Expense> $query */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->whereBetween('spent_at', [$from, $to]);
    }

    /** @param Builder<Expense> $query */
    public function scopeOfCategory(Builder $query, ?string $category): void
    {
        $query->when(
            $category !== null && ExpenseCategory::tryFrom($category) !== null,
            fn (Builder $q) => $q->where('category', $category),
        );
    }

    /** Physical-drawer outlays: legacy/manual expenses and supplier cash payments. */
    public function scopePaidFromCash(Builder $query): void
    {
        $query->where(function (Builder $paymentQuery): void {
            $paymentQuery->whereNull('payment_method')
                ->orWhere('payment_method', PaymentMethod::Cash->value);
        });
    }
}
