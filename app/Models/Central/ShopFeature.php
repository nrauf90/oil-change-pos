<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopFeature extends CentralModel
{
    protected $fillable = ['shop_id', 'module_key', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
