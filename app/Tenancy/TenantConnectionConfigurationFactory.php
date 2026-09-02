<?php

namespace App\Tenancy;

use Illuminate\Config\Repository as ConfigRepository;
use LogicException;

final readonly class TenantConnectionConfigurationFactory
{
    /** @var array<string, mixed> */
    private array $tenantConnectionTemplate;

    public function __construct(ConfigRepository $config)
    {
        $tenantConnectionTemplate = $config->get('database.tenant_connection_template');

        if (! is_array($tenantConnectionTemplate)) {
            throw new LogicException('The tenant database connection template is not configured.');
        }

        unset($tenantConnectionTemplate['url'], $tenantConnectionTemplate['name']);
        $this->tenantConnectionTemplate = $tenantConnectionTemplate;
    }

    /** @return array<string, mixed> */
    public function make(#[\SensitiveParameter] ValidatedTenantConnection $snapshot): array
    {
        $target = $snapshot->target();
        $configuration = array_replace(
            $this->tenantConnectionTemplate,
            $snapshot->connectionOverrides(),
        );
        unset($configuration['url'], $configuration['name']);
        $configuration['driver'] = $target->driver;
        $configuration['database'] = $target->database;

        if ($target->effectiveSocket !== null) {
            unset($configuration['host'], $configuration['port']);
            $configuration['unix_socket'] = $target->effectiveSocket;
        } elseif ($target->driver === 'mysql') {
            unset($configuration['unix_socket']);
            $configuration['host'] = $target->effectiveHost;
            $configuration['port'] = $target->effectivePort;
        } else {
            unset($configuration['host'], $configuration['port'], $configuration['unix_socket']);
        }

        return $configuration;
    }
}
