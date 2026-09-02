<?php

namespace App\Tenancy\Provisioning;

use App\Models\Central\Shop;

final readonly class NullTenantProvisioningHook implements TenantProvisioningHook
{
    public function reached(
        TenantProvisioningCheckpoint $checkpoint,
        #[\SensitiveParameter]
        Shop $shop,
    ): void {}
}
