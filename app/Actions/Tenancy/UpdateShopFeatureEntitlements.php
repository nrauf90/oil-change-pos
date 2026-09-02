<?php

namespace App\Actions\Tenancy;

use App\Enums\ShopLifecycleEvent;
use App\Exceptions\FeatureEntitlementUpdateUnavailable;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopFeature;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class UpdateShopFeatureEntitlements
{
    private const LOCK_STORE = 'feature_entitlement_locks';

    private const LOCK_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private ModuleRegistry $modules,
        private RecordShopLifecycleActivity $recordActivity,
        private CacheManager $cache,
    ) {}

    /** @param list<string> $enabledFeatureKeys */
    public function handle(PlatformUser $actor, Shop $shop, array $enabledFeatureKeys): int
    {
        $modules = $this->modules
            ->all()
            ->reject(static fn (Module $module): bool => $module->isCore());
        $enabledKeys = collect($enabledFeatureKeys);

        $lock = $this->featureLock($shop);
        $this->acquireLock($lock);

        try {
            return DB::connection('central')->transaction(
                function () use ($actor, $enabledKeys, $lock, $modules, $shop): int {
                    $this->refreshOwnedLock($lock);

                    $lockedActor = PlatformUser::on('central')
                        ->whereKey($actor->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedActor instanceof PlatformUser
                        || ! $lockedActor->is_active
                        || $lockedActor->role !== PlatformUser::ROLE_SUPER_ADMIN) {
                        throw new AuthorizationException(
                            'Your platform administrator access is no longer active.',
                        );
                    }

                    $lockedShop = Shop::on('central')
                        ->whereKey($shop->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedShop instanceof Shop) {
                        throw new AuthorizationException('The shop is no longer available.');
                    }

                    $storedFeatures = ShopFeature::on('central')
                        ->where('shop_id', $lockedShop->getKey())
                        ->orderBy('module_key')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('module_key');
                    $changes = 0;

                    foreach ($modules as $module) {
                        $storedFeature = $storedFeatures->get($module->key());
                        $currentlyEnabled = $storedFeature instanceof ShopFeature
                            ? $storedFeature->enabled
                            : $module->enabledByDefault();
                        $shouldEnable = $enabledKeys->contains($module->key());

                        if ($currentlyEnabled === $shouldEnable) {
                            continue;
                        }

                        ShopFeature::on('central')->updateOrCreate(
                            [
                                'shop_id' => $lockedShop->getKey(),
                                'module_key' => $module->key(),
                            ],
                            ['enabled' => $shouldEnable],
                        );
                        $this->recordActivity->handle(
                            $lockedShop,
                            $shouldEnable
                                ? ShopLifecycleEvent::FeatureEnabled
                                : ShopLifecycleEvent::FeatureDisabled,
                            $lockedActor,
                            [
                                'module_key' => $module->key(),
                                'reason_code' => 'platform_action',
                            ],
                        );
                        $changes++;
                    }

                    $this->refreshOwnedLock($lock);

                    return $changes;
                },
            );
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function featureLock(Shop $shop): DatabaseLock
    {
        $configuration = config('cache.stores.'.self::LOCK_STORE);

        if (! is_array($configuration)
            || ($configuration['driver'] ?? null) !== 'database'
            || ($configuration['connection'] ?? null) !== 'central'
            || ($configuration['lock_connection'] ?? null) !== 'central') {
            throw $this->lockUnavailable();
        }

        try {
            $repository = $this->cache->store(self::LOCK_STORE);
            $store = $repository->getStore();
            $centralConnection = DB::connection('central');
        } catch (Throwable $exception) {
            throw $this->lockUnavailable($exception);
        }

        if (! $store instanceof DatabaseStore
            || $store->getConnection() !== $centralConnection
            || $store->getLockConnection() !== $centralConnection) {
            throw $this->lockUnavailable();
        }

        try {
            $lock = $repository->lock(
                'shop-feature-entitlements:'.$shop->getKey(),
                self::LOCK_SECONDS,
            );
        } catch (Throwable $exception) {
            throw $this->lockUnavailable($exception);
        }

        if (! $lock instanceof DatabaseLock
            || $lock->getConnectionName() !== 'central') {
            throw $this->lockUnavailable();
        }

        return $lock;
    }

    private function acquireLock(DatabaseLock $lock): void
    {
        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (Throwable $exception) {
            throw $this->lockUnavailable($exception);
        }
    }

    private function refreshOwnedLock(DatabaseLock $lock): void
    {
        try {
            $refreshed = $lock->refresh(self::LOCK_SECONDS);
            $owned = $refreshed && $lock->isOwnedByCurrentProcess();
        } catch (Throwable $exception) {
            throw $this->lockUnavailable($exception);
        }

        if (! $owned) {
            throw $this->lockUnavailable();
        }
    }

    private function releaseLock(DatabaseLock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function lockUnavailable(?Throwable $previous = null): FeatureEntitlementUpdateUnavailable
    {
        return new FeatureEntitlementUpdateUnavailable(
            'Feature entitlements could not be updated safely. Please retry.',
            previous: $previous,
        );
    }
}
