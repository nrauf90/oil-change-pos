<?php

namespace App\Models;

use Database\Factories\VehicleModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleModel extends Model
{
    /** @use HasFactory<VehicleModelFactory> */
    use HasFactory;

    protected $fillable = ['vehicle_make_id', 'name'];

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
