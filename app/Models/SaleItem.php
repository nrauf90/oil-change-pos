<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SaleLineType;
use Database\Factories\SaleItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends TenantModel
{
    /** @use HasFactory<SaleItemFactory> */
    use HasFactory;

    protected $fillable = ['sale_id', 'item_id', 'item_name', 'type', 'quantity', 'dispensed_quantity', 'manually_charged_price'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SaleLineType::class,
            'quantity' => 'integer',
            // The total charged for this line, exactly as typed. Never a unit price,
            // so `quantity` must never be multiplied into it.
            'manually_charged_price' => 'decimal:2',
            'dispensed_quantity' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
