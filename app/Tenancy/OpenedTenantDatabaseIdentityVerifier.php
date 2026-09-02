<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenantDatabaseAttestationFailed;
use Illuminate\Database\Connection;
use PDO;

final readonly class OpenedTenantDatabaseIdentityVerifier
{
    public function openAndVerify(
        #[\SensitiveParameter]
        Connection $connection,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): PDO {
        resolve(TenantConnectionAttestationHook::class)->beforeOpen($target);

        if ($target->driver === 'mysql'
            && ! $target->hasCurrentMySqlEndpoints(resolve(DatabaseHostResolver::class))) {
            throw new TenantDatabaseAttestationFailed;
        }

        $pdo = $connection->getPdo();
        resolve(TenantConnectionAttestationHook::class)->afterOpen($pdo, $target);

        if ($target->driver === 'sqlite') {
            $this->verifySqliteConnection($pdo, $target);
        } elseif ($target->driver === 'mysql') {
            $this->verifyMySqlConnection($pdo, $target);
        } else {
            throw new TenantDatabaseAttestationFailed;
        }

        return $pdo;
    }

    public function verifySqliteConnection(
        PDO $pdo,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        if ($target->filesystemIdentity === null || ! $target->hasStableFilesystemIdentity) {
            throw new TenantDatabaseAttestationFailed;
        }

        $statement = $pdo->query('PRAGMA database_list');
        $databases = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        $mainDatabase = null;

        foreach ($databases as $database) {
            if (($database['name'] ?? null) === 'main' && is_string($database['file'] ?? null)) {
                $mainDatabase = $database['file'];
                break;
            }
        }

        clearstatcache(true, $target->database);
        $canonicalDatabase = is_string($mainDatabase) ? realpath($mainDatabase) : false;
        $expectedDatabase = realpath($target->database);

        if ($canonicalDatabase === false
            || $expectedDatabase === false
            || ! hash_equals($expectedDatabase, $canonicalDatabase)
            || ! hash_equals($target->filesystemIdentity, $this->filesystemIdentity($canonicalDatabase))) {
            throw new TenantDatabaseAttestationFailed;
        }
    }

    private function verifyMySqlConnection(
        PDO $pdo,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        $statement = $pdo->query('SELECT DATABASE()');
        $database = $statement === false ? false : $statement->fetchColumn();

        if (! is_string($database) || ! hash_equals($target->database, $database)) {
            throw new TenantDatabaseAttestationFailed;
        }
    }

    private function filesystemIdentity(#[\SensitiveParameter] string $database): string
    {
        clearstatcache(true, $database);
        $metadata = @stat($database);

        if (! is_array($metadata)
            || ! is_int($metadata['dev'] ?? null)
            || ! is_int($metadata['ino'] ?? null)
            || ($metadata['dev'] === 0 && $metadata['ino'] === 0)) {
            throw new TenantDatabaseAttestationFailed;
        }

        return $metadata['dev'].':'.$metadata['ino'];
    }
}
