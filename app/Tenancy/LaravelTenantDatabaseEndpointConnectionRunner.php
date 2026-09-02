<?php

namespace App\Tenancy;

use App\Exceptions\TenantDatabaseEndpointRotationException;
use Closure;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

final readonly class LaravelTenantDatabaseEndpointConnectionRunner implements TenantDatabaseEndpointConnectionRunner
{
    public function __construct(
        private DatabaseManager $database,
        private ConfigRepository $config,
        private TenantConnectionConfigurationFactory $configurationFactory,
        private OpenedTenantDatabaseIdentityVerifier $identityVerifier,
    ) {}

    public function run(
        #[\SensitiveParameter]
        ValidatedTenantConnection $candidate,
        Closure $operation,
    ): mixed {
        $target = $candidate->target();

        if ($target->driver !== 'mysql' || $target->effectiveSocket !== null) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_TARGET_INELIGIBLE',
                'Database endpoint rotation requires a remote MySQL target.',
            );
        }

        $configuration = $this->configurationFactory->make($candidate);
        $this->assertAuthenticatedTls($configuration);
        $connectionName = 'tenant_endpoint_rotation_'.str_replace('-', '', (string) Str::uuid());

        try {
            $this->config->set('database.connections.'.$connectionName, $configuration);
            $this->database->purge($connectionName);
            $connection = $this->database->connection($connectionName);
            $this->identityVerifier->openAndVerify($connection, $target);

            return $connection->transaction(
                fn (): mixed => $operation($connection),
            );
        } catch (TenantDatabaseEndpointRotationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_CONNECTION_FAILED',
                'The candidate tenant database could not be verified for endpoint rotation.',
            );
        } finally {
            $this->removeConnection($connectionName);
        }
    }

    /** @param array<string, mixed> $configuration */
    private function assertAuthenticatedTls(#[\SensitiveParameter] array $configuration): void
    {
        $options = $configuration['options'] ?? null;
        $caOption = defined('PDO::MYSQL_ATTR_SSL_CA') ? constant('PDO::MYSQL_ATTR_SSL_CA') : null;
        $verifyOption = defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')
            ? constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')
            : null;
        $caFile = is_array($options) && is_int($caOption) ? ($options[$caOption] ?? null) : null;
        $verifiesServer = is_array($options) && is_int($verifyOption)
            ? ($options[$verifyOption] ?? null)
            : null;

        if ($this->config->get('database.tenant_mysql_remote_provisioning_enabled') !== true
            || ! is_string($caFile)
            || $caFile === ''
            || ! is_file($caFile)
            || ! is_readable($caFile)
            || $verifiesServer !== true) {
            throw TenantDatabaseEndpointRotationException::safe(
                'ROTATION_AUTHENTICATED_TLS_REQUIRED',
                'Database endpoint rotation requires authenticated TLS and hostname verification.',
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
