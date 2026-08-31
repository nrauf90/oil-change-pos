<?php

namespace App\Models;

use Database\Factories\CustomerVehicleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerVehicle extends Model
{
    /** @use HasFactory<CustomerVehicleFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_name', 'phone', 'vehicle_model', 'vehicle_plate', 'mileage',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mileage' => 'integer',
        ];
    }
}
