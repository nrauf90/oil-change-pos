<?php

namespace App\Tenancy\Provisioning;

interface TenantProvisioningLease
{
    public function heartbeat(): void;
}
