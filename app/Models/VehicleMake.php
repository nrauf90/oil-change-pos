<?php

namespace App\Models;

use Database\Factories\VehicleMakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VehicleMake extends Model
{
    /** @use HasFactory<VehicleMakeFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    protected static function booted(): void
    {
        static::saving(function (self $make): void {
            $make->name = Str::squish($make->name);

            if (self::query()
                ->whereRaw('lower(name) = ?', [Str::lower($make->name)])
                ->when($make->exists, fn ($query) => $query->whereKeyNot($make->getKey()))
                ->exists()) {
                throw ValidationException::withMessages(['name' => 'This vehicle make already exists.']);
            }
        });
    }

    /** @return HasMany<VehicleModel, $this> */
    public function vehicleModels(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }
}
