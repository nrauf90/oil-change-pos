<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenantDatabaseAttestationFailed;
use PDO;

final class PdoTenantSqliteWitnessConnection implements TenantSqliteWitnessConnection
{
    public function open(
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): PDO {
        if ($target->driver !== 'sqlite') {
            throw new TenantDatabaseAttestationFailed;
        }

        return new PDO('sqlite:'.$target->database, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
