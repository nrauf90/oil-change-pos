<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bill the counter is still building — one per bay.
 *
 * `user_id`, `sale_id`, `status` and `version` are deliberately absent from
 * `$fillable`: who opened a bill comes from the session, whether it is finished
 * is decided by completing it, and the version is the guard that stops two
 * counter staff overwriting each other. None of the four can be posted.
 */
class Order extends TenantModel
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'label', 'customer_name', 'phone', 'vehicle_model', 'vehicle_plate',
        'mileage', 'next_checkup_mileage', 'notes', 'labor_charge', 'misc_charge', 'discount',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'labor_charge' => 'decimal:2',
            'misc_charge' => 'decimal:2',
            'discount' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->status ??= OrderStatus::Draft;
        });
    }

    /** @return HasMany<OrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function isDraft(): bool
    {
        return $this->status === OrderStatus::Draft;
    }

    /**
     * What the counter sees in the list. The label if someone typed one, then
     * the plate, then the customer — and failing all three, the time it was
     * opened, which is always something.
     */
    public function displayLabel(): string
    {
        foreach ([$this->label, $this->vehicle_plate, $this->customer_name] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Started '.$this->created_at?->format('d M, g:i A');
    }

    /** A draft left open this long is worth a second look, not a deletion. */
    public function isStale(?int $hours = null): bool
    {
        $hours ??= (int) config('pos.stale_draft_hours', 24);

        return $this->isDraft()
            && $this->created_at !== null
            && $this->created_at->lt(Carbon::now()->subHours($hours));
    }

    /** @param Builder<Order> $query */
    public function scopeDrafts(Builder $query): void
    {
        $query->where('status', OrderStatus::Draft);
    }
}
