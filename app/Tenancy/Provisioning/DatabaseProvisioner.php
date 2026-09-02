<?php

namespace App\Tenancy\Provisioning;

use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;

interface DatabaseProvisioner
{
    public function provision(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
        ?PlatformUser $actor = null,
    ): void;
}
