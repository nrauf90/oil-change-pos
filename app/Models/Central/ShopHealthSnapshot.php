<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopHealthSnapshot extends CentralModel
{
    protected $fillable = [
        'shop_id',
        'last_successful_connection_at',
        'migration_status',
        'last_activity_at',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'last_successful_connection_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
