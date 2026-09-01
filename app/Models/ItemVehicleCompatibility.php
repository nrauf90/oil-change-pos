<?php

namespace App\Models;

use Database\Factories\ItemVehicleCompatibilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ItemVehicleCompatibility extends Model
{
    /** @use HasFactory<ItemVehicleCompatibilityFactory> */
    use HasFactory;

    protected $fillable = ['item_id', 'vehicle_model_id', 'year_from', 'year_to'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year_from' => 'integer',
            'year_to' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $compatibility): void {
            if ($compatibility->year_from === null || $compatibility->year_to === null) {
                return;
            }

            if ($compatibility->year_from <= $compatibility->year_to) {
                return;
            }

            throw new ValidationException(Validator::make([], []), response: null, errorBag: 'default');
        });
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<VehicleModel, $this> */
    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class);
    }
}
