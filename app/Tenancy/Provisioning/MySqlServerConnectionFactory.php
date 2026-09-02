<?php

namespace App\Tenancy\Provisioning;

use App\Tenancy\ValidatedTenantConnection;

interface MySqlServerConnectionFactory
{
    public function open(
        #[\SensitiveParameter]
        ValidatedTenantConnection $snapshot,
    ): MySqlServerConnection;
}
