<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ShopAccessSession extends CentralModel
{
    use HasUuids;

    protected $guarded = ['*'];

    private bool $allowsEndTransition = false;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $session): void {
            $unexpectedChanges = array_diff(array_keys($session->getDirty()), ['ended_at']);

            if (! $session->allowsEndTransition || $unexpectedChanges !== []) {
                throw new LogicException('Support access sessions are immutable except for the end transition.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Support access sessions are append-only and cannot be deleted.');
        });
    }

    public static function start(
        PlatformUser $platformUser,
        Shop $shop,
        ?string $reason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): self {
        $session = new self;
        $session->platformUser()->associate($platformUser);
        $session->shop()->associate($shop);
        $session->forceFill([
            'started_at' => now(),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'reason' => $reason,
        ])->save();

        return $session;
    }

    public function end(): void
    {
        if ($this->ended_at !== null) {
            return;
        }

        $this->allowsEndTransition = true;

        try {
            $this->forceFill(['ended_at' => now()])->save();
        } finally {
            $this->allowsEndTransition = false;
        }
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
