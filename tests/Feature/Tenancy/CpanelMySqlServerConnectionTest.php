<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\TenantProvisioningException;
use App\Tenancy\Provisioning\CpanelMySqlServerConnection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shared hosting withholds the global CREATE privilege, so tenant databases are
 * created through the control panel instead. These cover the parts of that path
 * that can go wrong quietly.
 */
class CpanelMySqlServerConnectionTest extends TestCase
{
    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    /** A stub that records the argv it was asked to run. */
    private function connectionRunning(string $script, array $grantUsers = ['acct_user']): array
    {
        $binary = tempnam(sys_get_temp_dir(), 'uapi');
        file_put_contents($binary, $script);
        chmod($binary, 0o700);

        $connection = new CpanelMySqlServerConnection(
            $this->app['db']->connection(),
            static fn (): null => null,
            $binary,
            $grantUsers,
        );

        return [$connection, $binary];
    }

    #[Test]
    public function it_rejects_a_database_name_that_is_not_a_bare_identifier(): void
    {
        [$connection, $binary] = $this->connectionRunning("#!/bin/sh\nexit 0\n");

        $this->expectException(InvalidArgumentException::class);

        try {
            $connection->createDatabase('tenant`; DROP SCHEMA x; --');
        } finally {
            @unlink($binary);
        }
    }

    #[Test]
    public function it_rejects_a_database_name_over_the_mysql_limit(): void
    {
        [$connection, $binary] = $this->connectionRunning("#!/bin/sh\nexit 0\n");

        $this->expectException(InvalidArgumentException::class);

        try {
            $connection->createDatabase(str_repeat('a', 65));
        } finally {
            @unlink($binary);
        }
    }

    #[Test]
    public function a_uapi_payload_reporting_failure_is_treated_as_failure(): void
    {
        if (str_contains(PHP_OS_FAMILY, 'Windows')) {
            $this->markTestSkipped('Shell stub requires a POSIX shell.');
        }

        // UAPI reports refusal in the payload while still exiting zero, so a
        // zero exit code alone must never be read as success.
        [$connection, $binary] = $this->connectionRunning(
            "#!/bin/sh\necho '{\"result\":{\"status\":0,\"errors\":[\"denied\"]}}'\nexit 0\n",
        );

        try {
            $this->expectException(TenantProvisioningException::class);
            $connection->createDatabase('tenant_example');
        } finally {
            @unlink($binary);
        }
    }

    #[Test]
    public function a_successful_payload_creates_the_database_and_grants_every_user(): void
    {
        if (str_contains(PHP_OS_FAMILY, 'Windows')) {
            $this->markTestSkipped('Shell stub requires a POSIX shell.');
        }

        $log = tempnam(sys_get_temp_dir(), 'uapilog');
        [$connection, $binary] = $this->connectionRunning(
            "#!/bin/sh\necho \"\$@\" >> {$log}\necho '{\"result\":{\"status\":1}}'\nexit 0\n",
            ['user_one', 'user_two'],
        );

        try {
            $connection->createDatabase('tenant_example');

            $calls = (string) file_get_contents($log);

            $this->assertStringContainsString('create_database name=tenant_example', $calls);
            $this->assertStringContainsString('user=user_one', $calls);
            $this->assertStringContainsString('user=user_two', $calls);
            // A database with no grants cannot be opened by the tenant connection.
            $this->assertSame(2, substr_count($calls, 'set_privileges_on_database'));
        } finally {
            @unlink($binary);
            @unlink($log);
        }
    }

    #[Test]
    public function a_non_zero_exit_status_is_treated_as_failure(): void
    {
        if (str_contains(PHP_OS_FAMILY, 'Windows')) {
            $this->markTestSkipped('Shell stub requires a POSIX shell.');
        }

        [$connection, $binary] = $this->connectionRunning("#!/bin/sh\nexit 3\n");

        try {
            $this->expectException(TenantProvisioningException::class);
            $connection->createDatabase('tenant_example');
        } finally {
            @unlink($binary);
        }
    }
}
