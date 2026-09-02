<?php

namespace App\Tenancy;

use PDO;

interface TenantSqliteWitnessConnection
{
    public function open(
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): PDO;
}
