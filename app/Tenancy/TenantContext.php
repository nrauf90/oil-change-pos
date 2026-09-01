<?php

namespace App\Tenancy;

use App\Models\Central\Shop;
use App\Tenancy\Exceptions\TenantNotInitialized;
use LogicException;

class TenantContext
{
    private ?Shop $shop = null;

    private ?string $shopId = null;

    public function initialize(#[\SensitiveParameter] Shop $shop): void
    {
        $shopId = $shop->getKey();

        if (! $shop->exists || ! is_string($shopId) || $shopId === '') {
            throw new LogicException('Tenant context requires a persisted shop.');
        }

        if ($this->shopId !== null && ! hash_equals($this->shopId, $shopId)) {
            throw new LogicException('A different tenant is already initialized.');
        }

        $this->shopId = $shopId;
        $this->shop = clone $shop;
    }

    public function initialized(): bool
    {
        return $this->shop !== null && $this->shopId !== null;
    }

    public function shop(): Shop
    {
        if ($this->shop === null) {
            throw new TenantNotInitialized;
        }

        return clone $this->shop;
    }

    public function id(): string
    {
        if ($this->shopId === null) {
            throw new TenantNotInitialized;
        }

        return $this->shopId;
    }

    public function clear(): void
    {
        $this->shop = null;
        $this->shopId = null;
    }
}
