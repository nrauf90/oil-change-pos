<?php

namespace App\Tenancy\Provisioning;

use App\Models\Central\Shop;

/** Internal checkpoint observer; the production binding is deliberately a no-op. */
interface TenantProvisioningHook
{
    public function reached(
        TenantProvisioningCheckpoint $checkpoint,
        #[\SensitiveParameter]
        Shop $shop,
    ): void;
}
