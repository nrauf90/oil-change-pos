<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends TenantModel
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'address', 'notes'];

    /** @return HasMany<Supply, $this> */
    public function supplies(): HasMany
    {
        return $this->hasMany(Supply::class);
    }
}
