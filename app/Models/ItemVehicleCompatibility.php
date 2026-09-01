<?php

namespace App\Models;

use App\Enums\ItemType;
use Database\Factories\ItemVehicleCompatibilityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ItemVehicleCompatibility extends TenantModel
{
    private const MAX_VEHICLE_YEAR = 2026;

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
            foreach (['year_from', 'year_to'] as $field) {
                $year = $compatibility->{$field};
                if ($year !== null && ($year < 2000 || $year > self::MAX_VEHICLE_YEAR)) {
                    throw ValidationException::withMessages([
                        $field => 'The year must be between 2000 and 2026.',
                    ]);
                }
            }

            $item = Item::query()->find($compatibility->item_id);

            if ($item === null || $item->type !== ItemType::Product || $item->is_universal) {
                throw ValidationException::withMessages([
                    'item_id' => 'Vehicle compatibility can only be assigned to vehicle-specific products.',
                ]);
            }

            if ($compatibility->year_from !== null
                && $compatibility->year_to !== null
                && $compatibility->year_from > $compatibility->year_to) {
                throw ValidationException::withMessages([
                    'year_to' => 'The ending year must be after or equal to the starting year.',
                ]);
            }

            $duplicate = self::query()
                ->where('item_id', $compatibility->item_id)
                ->where('vehicle_model_id', $compatibility->vehicle_model_id)
                ->when(
                    $compatibility->year_from === null,
                    fn (Builder $query): Builder => $query->whereNull('year_from'),
                    fn (Builder $query): Builder => $query->where('year_from', $compatibility->year_from),
                )
                ->when(
                    $compatibility->year_to === null,
                    fn (Builder $query): Builder => $query->whereNull('year_to'),
                    fn (Builder $query): Builder => $query->where('year_to', $compatibility->year_to),
                )
                ->when($compatibility->exists, fn (Builder $query): Builder => $query->whereKeyNot($compatibility->getKey()))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'vehicle_model_id' => 'This vehicle compatibility range already exists.',
                ]);
            }
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
