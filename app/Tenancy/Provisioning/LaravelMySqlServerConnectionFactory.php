<?php

namespace App\Tenancy\Provisioning;

use App\Exceptions\TenantProvisioningException;
use App\Tenancy\TenantConnectionConfigurationFactory;
use App\Tenancy\ValidatedTenantConnection;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

final readonly class LaravelMySqlServerConnectionFactory implements MySqlServerConnectionFactory
{
    public function __construct(
        private DatabaseManager $database,
        private ConfigRepository $config,
        private TenantConnectionConfigurationFactory $configurationFactory,
    ) {}

    public function open(
        #[\SensitiveParameter]
        ValidatedTenantConnection $snapshot,
    ): MySqlServerConnection {
        $target = $snapshot->target();

        if ($target->driver !== 'mysql') {
            throw TenantProvisioningException::safe(
                'target',
                'UNSUPPORTED_DATABASE_DRIVER',
                'The server connection requires a MySQL tenant target.',
            );
        }

        $configuration = $this->configurationFactory->make($snapshot);
        $this->assertReleaseGate($configuration, $target->effectiveSocket !== null);
        $configuration['database'] = 'information_schema';
        unset($configuration['url'], $configuration['name']);
        $connectionName = 'tenant_mysql_server_'.str_replace('-', '', (string) Str::uuid());

        try {
            $this->config->set('database.connections.'.$connectionName, $configuration);
            $this->database->purge($connectionName);
            $connection = $this->database->connection($connectionName);
        } catch (Throwable) {
            $this->removeConnection($connectionName);

            throw TenantProvisioningException::safe(
                'connection',
                'MYSQL_SERVER_CONNECTION_FAILED',
                'The MySQL server connection could not be opened. Review the application log code and retry.',
            );
        }

        return new LaravelMySqlServerConnection(
            $connection,
            fn (): mixed => $this->removeConnection($connectionName),
        );
    }

    /** @param array<string, mixed> $configuration */
    private function assertReleaseGate(
        #[\SensitiveParameter]
        array $configuration,
        bool $usesLocalSocket,
    ): void {
        if ($usesLocalSocket) {
            return;
        }

        if ($this->config->get('database.tenant_mysql_remote_provisioning_enabled') !== true) {
            throw TenantProvisioningException::safe(
                'database',
                'MYSQL_REMOTE_PROVISIONING_DISABLED',
                'Remote TCP MySQL provisioning is not enabled for this deployment.',
            );
        }

        $options = $configuration['options'] ?? null;
        $caOption = defined('PDO::MYSQL_ATTR_SSL_CA') ? constant('PDO::MYSQL_ATTR_SSL_CA') : null;
        $verifyOption = defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')
            ? constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')
            : null;
        $caFile = is_array($options) && is_int($caOption) ? ($options[$caOption] ?? null) : null;
        $verifiesServer = is_array($options) && is_int($verifyOption)
            ? ($options[$verifyOption] ?? null)
            : null;

        if (! is_string($caFile)
            || $caFile === ''
            || ! is_file($caFile)
            || ! is_readable($caFile)
            || $verifiesServer !== true) {
            throw TenantProvisioningException::safe(
                'database',
                'MYSQL_AUTHENTICATED_TLS_REQUIRED',
                'Remote TCP MySQL provisioning requires authenticated TLS and hostname verification.',
            );
        }
    }

    private function removeConnection(string $connectionName): void
    {
        try {
            $this->database->purge($connectionName);
        } catch (Throwable) {
        }

        try {
            $connections = $this->config->get('database.connections');

            if (is_array($connections)) {
                unset($connections[$connectionName]);
                $this->config->set('database.connections', $connections);
            }
        } catch (Throwable) {
        }
    }
}
