<?php

namespace App\Tenancy;

use App\Models\Central\ShopFeature;
use Throwable;

/**
 * Applies the platform-owned feature ceiling for the current tenant.
 *
 * Missing rows preserve the caller-supplied module default. Once a tenant
 * context exists, a failed central lookup denies optional features rather
 * than accidentally exposing them.
 */
class TenantFeatureGate
{
    /** @var array<string, bool>|null */
    private ?array $enabledByModule = null;

    private ?string $cachedShopId = null;

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function enabled(string $moduleKey, bool $enabledByDefault = true): bool
    {
        if (! $this->tenantContext->initialized()) {
            return true;
        }

        try {
            $shopId = $this->tenantContext->id();

            if ($this->cachedShopId !== $shopId || $this->enabledByModule === null) {
                $this->cachedShopId = $shopId;
                $this->enabledByModule = ShopFeature::on('central')
                    ->where('shop_id', $shopId)
                    ->pluck('enabled', 'module_key')
                    ->map(static fn (mixed $enabled): bool => (bool) $enabled)
                    ->all();
            }

            return $this->enabledByModule[$moduleKey] ?? $enabledByDefault;
        } catch (Throwable) {
            return false;
        }
    }

    public function flush(): void
    {
        $this->cachedShopId = null;
        $this->enabledByModule = null;
    }
}
