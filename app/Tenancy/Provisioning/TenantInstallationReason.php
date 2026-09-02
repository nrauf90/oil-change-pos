<?php

namespace App\Tenancy\Provisioning;

enum TenantInstallationReason: string
{
    case ExclusiveCreate = 'exclusive_create';
    case ForcedAdoption = 'forced_adoption';
}
