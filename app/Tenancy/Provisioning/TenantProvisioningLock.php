<?php

namespace App\Tenancy\Provisioning;

use App\Exceptions\TenantProvisioningException;
use App\Models\Central\Shop;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Config\Repository as ConfigRepository;
use Throwable;

final readonly class TenantProvisioningLock
{
    private const STORE = 'database';

    public function __construct(
        private CacheManager $cache,
        private ConfigRepository $config,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(TenantProvisioningLease): TResult  $operation
     * @return TResult
     */
    public function run(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        Closure $operation,
    ): mixed {
        $this->assertCentralStore();
        $seconds = $this->leaseSeconds();
        $lock = $this->cache->store(self::STORE)->lock(
            'tenant-provision:'.$shop->getKey(),
            $seconds,
        );

        if (! $lock instanceof DatabaseLock) {
            throw TenantProvisioningException::safe(
                'database',
                'PROVISIONING_LOCK_MISCONFIGURED',
                'Provisioning requires a renewable central database lock.',
            );
        }

        try {
            if (! $lock->get()) {
                throw TenantProvisioningException::safe(
                    'database',
                    'PROVISIONING_BUSY',
                    'Another provisioning attempt is already running for this shop.',
                );
            }
        } catch (TenantProvisioningException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw TenantProvisioningException::safe(
                'database',
                'PROVISIONING_LOCK_UNAVAILABLE',
                'The shared provisioning lock is unavailable. Retry later.',
            );
        }

        $lease = new DatabaseTenantProvisioningLease($lock, $seconds);

        try {
            return $operation($lease);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    private function leaseSeconds(): int
    {
        $configured = $this->config->get('database.tenant_provisioning_lock_seconds', 900);

        if (is_int($configured)) {
            $seconds = $configured;
        } elseif (is_string($configured) && ctype_digit($configured)) {
            $seconds = (int) $configured;
        } else {
            $seconds = 0;
        }

        if ($seconds < 1) {
            throw TenantProvisioningException::safe(
                'database',
                'PROVISIONING_LOCK_MISCONFIGURED',
                'Provisioning requires a positive central database lock duration.',
            );
        }

        return $seconds;
    }

    private function assertCentralStore(): void
    {
        if ($this->config->get('cache.stores.'.self::STORE.'.driver') !== 'database'
            || $this->config->get('cache.stores.'.self::STORE.'.connection') !== 'central'
            || $this->config->get('cache.stores.'.self::STORE.'.lock_connection') !== 'central') {
            throw TenantProvisioningException::safe(
                'database',
                'PROVISIONING_LOCK_MISCONFIGURED',
                'Provisioning requires the shared central database lock store.',
            );
        }
    }
}
