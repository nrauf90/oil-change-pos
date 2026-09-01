<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

final class TenantDatabaseAttestationFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tenant database identity attestation failed.');
    }
}
