<?php

namespace App\Tenancy\Provisioning;

use Closure;
use Illuminate\Database\Connection;
use InvalidArgumentException;

final class LaravelMySqlServerConnection implements MySqlServerConnection
{
    private bool $closed = false;

    public function __construct(
        #[\SensitiveParameter]
        private readonly Connection $connection,
        private readonly Closure $cleanup,
    ) {}

    public function databaseExists(#[\SensitiveParameter] string $database): bool
    {
        $this->assertValidDatabaseName($database);

        return $this->connection->selectOne(
            <<<'SQL'
                SELECT SCHEMA_NAME
                FROM INFORMATION_SCHEMA.SCHEMATA
                WHERE SCHEMA_NAME = ?
                LIMIT 1
                SQL,
            [$database],
        ) !== null;
    }

    public function createDatabase(#[\SensitiveParameter] string $database): void
    {
        $this->assertValidDatabaseName($database);
        $this->connection->getSchemaBuilder()->createDatabase($database);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        ($this->cleanup)();
    }

    private function assertValidDatabaseName(#[\SensitiveParameter] string $database): void
    {
        if (strlen($database) > 64 || preg_match('/\A[A-Za-z0-9_]+\z/', $database) !== 1) {
            throw new InvalidArgumentException('The MySQL database name is invalid.');
        }
    }
}
