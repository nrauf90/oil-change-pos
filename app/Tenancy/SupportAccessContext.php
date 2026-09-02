<?php

namespace App\Tenancy;

use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopAccessSession;
use LogicException;

final class SupportAccessContext
{
    private ?ShopAccessSession $audit = null;

    private ?Shop $shop = null;

    private ?PlatformUser $platformUser = null;

    private ?SupportAccessPrincipal $principal = null;

    private bool $shopUnavailable = false;

    public function active(): bool
    {
        return $this->audit instanceof ShopAccessSession
            && $this->shop instanceof Shop
            && $this->platformUser instanceof PlatformUser
            && $this->principal instanceof SupportAccessPrincipal;
    }

    public function activate(
        ShopAccessSession $audit,
        Shop $shop,
        PlatformUser $platformUser,
    ): void {
        $this->audit = $audit;
        $this->shop = $shop;
        $this->platformUser = $platformUser;
        $this->principal = new SupportAccessPrincipal($audit, $platformUser, $shop);
    }

    public function audit(): ShopAccessSession
    {
        return $this->audit ?? throw new LogicException('Support access is not active.');
    }

    public function shop(): Shop
    {
        return $this->shop ?? throw new LogicException('Support access is not active.');
    }

    public function platformUser(): PlatformUser
    {
        return $this->platformUser ?? throw new LogicException('Support access is not active.');
    }

    public function principal(): SupportAccessPrincipal
    {
        return $this->principal ?? throw new LogicException('Support access is not active.');
    }

    public function clear(): void
    {
        $this->audit = null;
        $this->shop = null;
        $this->platformUser = null;
        $this->principal = null;
        $this->shopUnavailable = false;
    }

    public function markShopUnavailable(): void
    {
        $this->shopUnavailable = true;
    }

    public function shopUnavailable(): bool
    {
        return $this->shopUnavailable;
    }
}
