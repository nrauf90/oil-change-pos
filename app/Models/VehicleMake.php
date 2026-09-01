<?php

namespace App\Models;

use Database\Factories\VehicleMakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleMake extends Model
{
    /** @use HasFactory<VehicleMakeFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    /** @return HasMany<VehicleModel, $this> */
    public function vehicleModels(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }
}
