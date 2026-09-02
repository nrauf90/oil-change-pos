<?php

namespace App\Tenancy;

use App\Models\Central\Shop;
use Illuminate\Config\Repository as ConfigRepository;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

final readonly class TenantPermissionCache
{
    private const CACHE_KEY_PREFIX = 'spatie.permission.cache.tenant.';

    private const NO_TENANT_CACHE_KEY = 'spatie.permission.cache.no-tenant';

    public function __construct(
        private ConfigRepository $config,
        private PermissionRegistrar $registrar,
    ) {}

    public function activate(#[\SensitiveParameter] Shop $shop): void
    {
        $this->resetRegistrar($this->keyFor($shop));
    }

    public function clear(): void
    {
        $this->resetRegistrar($this->keyWithoutTenant());
    }

    public function keyFor(#[\SensitiveParameter] Shop|string $shop): string
    {
        $shopId = $shop instanceof Shop ? $shop->getKey() : $shop;

        if (! is_string($shopId) || $shopId === '') {
            throw new InvalidArgumentException('A tenant permission cache key requires a shop identifier.');
        }

        return self::CACHE_KEY_PREFIX.$shopId;
    }

    public function keyWithoutTenant(): string
    {
        return self::NO_TENANT_CACHE_KEY;
    }

    private function resetRegistrar(string $cacheKey): void
    {
        $this->registrar->clearPermissionsCollection();
        $this->config->set('permission.cache.key', $cacheKey);
        $this->registrar->initializeCache();
    }
}
