<?php

namespace App\Models\Concerns;

use App\Tenancy\TenantContext;
use LogicException;

trait UsesTenantConnection
{
    public function getConnectionName(): string
    {
        resolve(TenantContext::class)->id();

        return 'tenant';
    }

    public function setConnection($name)
    {
        if ($name !== 'tenant') {
            throw new LogicException('Operational models cannot override the tenant connection.');
        }

        resolve(TenantContext::class)->id();

        return parent::setConnection('tenant');
    }
}
