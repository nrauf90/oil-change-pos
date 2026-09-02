<?php

namespace App\Tenancy;

use PDO;

final class NullTenantConnectionAttestationHook implements TenantConnectionAttestationHook
{
    public function beforeOpen(
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {}

    public function afterOpen(
        PDO $pdo,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {}

    public function afterSqliteNonceWritten(
        PDO $pdo,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {}
}
