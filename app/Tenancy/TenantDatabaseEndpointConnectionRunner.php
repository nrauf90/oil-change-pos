<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Database\Connection;

interface TenantDatabaseEndpointConnectionRunner
{
    /**
     * @template TResult
     *
     * @param  Closure(Connection): TResult  $operation
     * @return TResult
     */
    public function run(
        #[\SensitiveParameter]
        ValidatedTenantConnection $candidate,
        Closure $operation,
    ): mixed;
}
