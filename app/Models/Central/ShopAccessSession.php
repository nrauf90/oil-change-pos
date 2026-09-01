<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopAccessSession extends CentralModel
{
    protected $fillable = [
        'platform_user_id',
        'shop_id',
        'started_at',
        'ended_at',
        'ip_address',
        'user_agent',
        'reason',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class);
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
