<?php

namespace App\Actions\Tenancy;

use App\Enums\ShopLifecycleEvent;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopLifecycleActivity;

class RecordShopLifecycleActivity
{
    private const REDACTED_KEYS = [
        'api_token',
        'database_password',
        'password',
        'password_confirmation',
        'password_hash',
        'remember_token',
    ];

    /** @param array<string, mixed> $metadata */
    public function handle(
        Shop $shop,
        ShopLifecycleEvent $event,
        ?PlatformUser $actor = null,
        array $metadata = [],
    ): ShopLifecycleActivity {
        $activity = new ShopLifecycleActivity;
        $activity->shop()->associate($shop);

        if ($actor !== null) {
            $activity->platformUser()->associate($actor);
        }

        $redactedMetadata = $this->redact($metadata);
        $activity->forceFill([
            'actor_name' => $actor?->name ?? 'System',
            'event' => $event,
            'metadata' => $redactedMetadata === [] ? null : $redactedMetadata,
            'occurred_at' => now(),
        ])->save();

        return $activity;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function redact(array $metadata): array
    {
        $redacted = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }
}
