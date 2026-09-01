<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopDatabaseTargetClaim extends CentralModel
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['fingerprint'];

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
