<?php

namespace App\Tenancy\Provisioning;

use App\Exceptions\TenantProvisioningException;
use Closure;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/**
 * Creates tenant databases through the cPanel UAPI instead of CREATE DATABASE.
 *
 * Shared hosting does not grant an account's MySQL user the global CREATE
 * privilege, so `CREATE DATABASE` fails outright no matter how the connection
 * is configured. cPanel exposes database creation to the account's shell user
 * through `uapi Mysql create_database`, which runs as the account and is the
 * only supported way to add one.
 *
 * Existence checks still go through the ordinary SQL connection — reading
 * INFORMATION_SCHEMA needs no special privilege, and keeping it on the same
 * connection the provisioner already validated means the check and the
 * subsequent work agree about what they are looking at.
 *
 * Everything above this class is unchanged: target claims, creation
 * authorization receipts and attestation all still apply. Only the mechanism
 * that brings the database into existence differs.
 */
final class CpanelMySqlServerConnection implements MySqlServerConnection
{
    private bool $closed = false;

    /**
     * @param  list<string>  $grantUsers  Account MySQL users granted ALL PRIVILEGES
     *                                    on each new database. cPanel creates a
     *                                    database with no grants at all, so without
     *                                    this the tenant connection cannot open it.
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly Connection $connection,
        private readonly Closure $cleanup,
        private readonly string $uapiBinary,
        private readonly array $grantUsers,
        private readonly int $timeoutSeconds = 60,
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

        $this->runUapi(['Mysql', 'create_database', 'name='.$database], 'CPANEL_CREATE_DATABASE_FAILED');

        foreach ($this->grantUsers as $user) {
            $this->runUapi(
                [
                    'Mysql',
                    'set_privileges_on_database',
                    'user='.$user,
                    'database='.$database,
                    'privileges=ALL PRIVILEGES',
                ],
                'CPANEL_GRANT_FAILED',
            );
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        ($this->cleanup)();
    }

    /** @param list<string> $arguments */
    private function runUapi(array $arguments, string $errorCode): void
    {
        $process = new Process([$this->uapiBinary, '--output=json', ...$arguments]);
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw $this->failure($errorCode);
        }

        $decoded = json_decode($process->getOutput(), true);

        // UAPI reports failure in the payload, not the exit status, so a zero
        // exit code alone is not evidence that anything happened.
        if (! is_array($decoded)
            || ! isset($decoded['result'])
            || ! is_array($decoded['result'])
            || (int) ($decoded['result']['status'] ?? 0) !== 1) {
            throw $this->failure($errorCode);
        }
    }

    private function failure(string $errorCode): TenantProvisioningException
    {
        return TenantProvisioningException::safe(
            'database',
            $errorCode,
            'The hosting control panel refused the database operation. Review the panel and retry.',
        );
    }

    private function assertValidDatabaseName(#[\SensitiveParameter] string $database): void
    {
        if (strlen($database) > 64 || preg_match('/\A[A-Za-z0-9_]+\z/', $database) !== 1) {
            throw new InvalidArgumentException('The MySQL database name is invalid.');
        }
    }
}
