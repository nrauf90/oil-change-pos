<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\InitializeTenantContext;
use App\Models\Central\Shop;
use LogicException;

trait RunsInTenantContext
{
    private string $tenantShopId;

    final protected function runInTenantContext(Shop $shop): void
    {
        $shopId = $shop->getKey();

        if ($shop::class !== Shop::class || ! $shop->exists || ! is_string($shopId) || $shopId === '') {
            throw new LogicException('Tenant jobs require a persisted shop.');
        }

        $this->tenantShopId = $shopId;
    }

    /** @return list<object> */
    final public function middleware(): array
    {
        return [new InitializeTenantContext($this->tenantShopId)];
    }
}
