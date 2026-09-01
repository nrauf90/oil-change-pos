<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;

final class TenantDatabaseTargetConflict extends DomainException implements ShouldntReport
{
    public static function centralDatabase(): self
    {
        return new self('The central platform database cannot be assigned to a shop.');
    }

    public static function alreadyAssigned(): self
    {
        return new self('The tenant database target is already assigned to another shop.');
    }
}
