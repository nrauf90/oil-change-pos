<?php

namespace App\Models;

use Database\Factories\VehicleModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VehicleModel extends TenantModel
{
    /** @use HasFactory<VehicleModelFactory> */
    use HasFactory;

    protected $fillable = ['vehicle_make_id', 'name'];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $model->name = Str::squish($model->name);

            if (self::query()
                ->where('vehicle_make_id', $model->vehicle_make_id)
                ->whereRaw('lower(name) = ?', [Str::lower($model->name)])
                ->when($model->exists, fn ($query) => $query->whereKeyNot($model->getKey()))
                ->exists()) {
                throw ValidationException::withMessages(['name' => 'This vehicle model already exists for the selected make.']);
            }
        });
    }

    /** @return BelongsTo<VehicleMake, $this> */
    public function vehicleMake(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class);
    }

    /** @return HasMany<ItemVehicleCompatibility, $this> */
    public function itemCompatibilities(): HasMany
    {
        return $this->hasMany(ItemVehicleCompatibility::class);
    }
}
