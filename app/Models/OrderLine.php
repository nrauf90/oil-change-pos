<?php

namespace App\Models;

use App\Enums\SaleLineType;
use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a bill still being built.
 *
 * Nothing here has moved money or stock yet. Completing the order copies these
 * across to `sale_items`, and only then do both happen.
 */
class OrderLine extends TenantModel
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    protected $fillable = [
        'item_id', 'item_name', 'type', 'quantity', 'dispensed_quantity', 'manually_charged_price',
    ];

    protected function casts(): array
    {
        return [
            'type' => SaleLineType::class,
            'quantity' => 'integer',
            'dispensed_quantity' => 'decimal:3',
            'manually_charged_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
