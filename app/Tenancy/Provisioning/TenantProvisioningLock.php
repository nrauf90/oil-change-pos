<?php

namespace App\Tenancy\Provisioning;

use App\Exceptions\TenantProvisioningException;
use App\Models\Central\Shop;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Throwable;

final readonly class TenantProvisioningLock
{
    private const STORE = 'database';

    private const SECONDS = 900;

    public function __construct(
        private CacheManager $cache,
        private ConfigRepository $config,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        Closure $operation,
    ): mixed {
        $this->assertCentralStore();
        $lock = $this->cache->store(self::STORE)->lock(
            'tenant-provision:'.$shop->getKey(),
            self::SECONDS,
        );

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

        try {
            return $operation();
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
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
