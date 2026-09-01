<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ShopAccessSession extends CentralModel
{
    use HasUuids;

    protected $guarded = ['*'];

    private bool $allowsStartTransition = false;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            if (! $session->allowsStartTransition) {
                throw new LogicException('Support access sessions must be started through start().');
            }
        });

        static::updating(function (): void {
            throw new LogicException('Support access sessions are immutable except for the end transition.');
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
        ]);
        $session->allowsStartTransition = true;

        try {
            $session->save();
        } finally {
            $session->allowsStartTransition = false;
        }

        return $session;
    }

    public function end(): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        $this->refresh();
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
