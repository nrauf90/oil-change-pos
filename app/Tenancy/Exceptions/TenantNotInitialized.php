<?php

namespace App\Tenancy\Exceptions;

use LogicException;

final class TenantNotInitialized extends LogicException
{
    public function __construct()
    {
        parent::__construct('Tenant context has not been initialized.');
    }
}
