<?php

namespace App\Tenancy;

use App\Models\Central\Shop;
use App\Tenancy\Exceptions\TenantNotInitialized;

final class TenantRuntimeState
{
    private ?TenantConnectionLease $lease = null;

    private ?Shop $shop = null;

    private ?string $shopId = null;

    public function activate(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        TenantConnectionLease $lease,
    ): void {
        $this->lease = $lease;
        $this->shop = clone $shop;
        $this->shopId = (string) $shop->getKey();
    }

    public function deactivate(): void
    {
        $this->lease = null;
        $this->shop = null;
        $this->shopId = null;
    }

    public function isOwnedBy(#[\SensitiveParameter] TenantConnectionLease $lease): bool
    {
        return $this->lease?->owns($lease) ?? false;
    }

    public function initialized(): bool
    {
        return $this->lease !== null && $this->shop !== null && $this->shopId !== null;
    }

    public function shop(): Shop
    {
        if ($this->shop === null || $this->lease === null) {
            throw new TenantNotInitialized;
        }

        return clone $this->shop;
    }

    public function id(): string
    {
        if ($this->shopId === null || $this->lease === null) {
            throw new TenantNotInitialized;
        }

        return $this->shopId;
    }
}
