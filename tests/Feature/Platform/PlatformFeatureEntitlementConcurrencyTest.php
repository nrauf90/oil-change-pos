<?php

namespace Tests\Feature\Platform;

use App\Actions\Tenancy\UpdateShopFeatureEntitlements;
use App\Enums\ShopLifecycleEvent;
use App\Exceptions\FeatureEntitlementUpdateUnavailable;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopFeature;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Component\Process\Process;

class PlatformFeatureEntitlementConcurrencyTest extends PlatformTestCase
{
    public function test_feature_lock_store_is_pinned_to_the_central_database(): void
    {
        $this->assertSame('database', config('cache.stores.feature_entitlement_locks.driver'));
        $this->assertSame('central', config('cache.stores.feature_entitlement_locks.connection'));
        $this->assertSame('central', config('cache.stores.feature_entitlement_locks.lock_connection'));
    }

    public function test_feature_updates_ignore_an_unsafe_default_cache_store(): void
    {
        $this->configureFeatureLockStore();
        config()->set('cache.default', 'array');
        app(CacheManager::class)->forgetDriver(['array', 'feature_entitlement_locks']);
        $shop = Shop::factory()->create();
        $actor = PlatformUser::factory()->create();
        $heldLock = Cache::store('feature_entitlement_locks')->lock(
            $this->featureLockName($shop),
            60,
        );
        $this->assertTrue($heldLock->acquire());
        Sleep::fake(syncWithCarbon: true);
        $caughtException = null;

        try {
            resolve(UpdateShopFeatureEntitlements::class)->handle(
                $actor,
                $shop,
                ['reports', 'expenses', 'workshop'],
            );
        } catch (FeatureEntitlementUpdateUnavailable $exception) {
            $caughtException = $exception;
        } finally {
            $heldLock->release();
            Sleep::fake(false);
            Carbon::setTestNow();
        }

        $this->assertInstanceOf(FeatureEntitlementUpdateUnavailable::class, $caughtException);
        $this->assertSame(
            'Feature entitlements could not be updated safely. Please retry.',
            $caughtException?->getMessage(),
        );
        $this->assertDatabaseCount('shop_features', 0, 'central');
        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_feature_updates_use_the_named_store_when_the_default_is_array(): void
    {
        $this->configureFeatureLockStore();
        config()->set('cache.default', 'array');
        app(CacheManager::class)->forgetDriver(['array', 'feature_entitlement_locks']);
        $shop = Shop::factory()->create();
        $actor = PlatformUser::factory()->create();

        $changes = resolve(UpdateShopFeatureEntitlements::class)->handle(
            $actor,
            $shop,
            ['reports', 'expenses', 'workshop'],
        );

        $this->assertSame(1, $changes);
        $this->assertDatabaseHas('shop_features', [
            'shop_id' => $shop->getKey(),
            'module_key' => 'scripts',
            'enabled' => false,
        ], 'central');
        $this->assertSame(1, $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::FeatureDisabled)
            ->count());
    }

    /** @param array<string, mixed>|null $storeConfiguration */
    #[DataProvider('unsafeFeatureLockStores')]
    public function test_feature_updates_fail_closed_for_misconfigured_named_lock_store(
        ?array $storeConfiguration,
    ): void {
        config()->set('cache.stores.feature_entitlement_locks', $storeConfiguration);
        app(CacheManager::class)->forgetDriver('feature_entitlement_locks');
        $shop = Shop::factory()->create();
        $actor = PlatformUser::factory()->create();
        $caughtException = null;

        try {
            resolve(UpdateShopFeatureEntitlements::class)->handle(
                $actor,
                $shop,
                ['reports', 'expenses', 'workshop'],
            );
        } catch (FeatureEntitlementUpdateUnavailable $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(FeatureEntitlementUpdateUnavailable::class, $caughtException);
        $this->assertDatabaseCount('shop_features', 0, 'central');
        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_feature_updates_reject_an_unsafe_concrete_named_store(): void
    {
        $this->configureFeatureLockStore();
        $this->replaceFeatureLockStore(new Repository(new ArrayStore));
        $shop = Shop::factory()->create();
        $actor = PlatformUser::factory()->create();
        $caughtException = null;

        try {
            resolve(UpdateShopFeatureEntitlements::class)->handle(
                $actor,
                $shop,
                ['reports', 'expenses', 'workshop'],
            );
        } catch (FeatureEntitlementUpdateUnavailable $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(FeatureEntitlementUpdateUnavailable::class, $caughtException);
        $this->assertDatabaseCount('shop_features', 0, 'central');
        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_unavailable_lock_database_fails_with_the_safe_domain_exception(): void
    {
        $this->configureFeatureLockStore();
        $shop = Shop::factory()->create();
        $actor = PlatformUser::factory()->create();
        Schema::connection('central')->drop('cache_locks');
        $caughtException = null;

        try {
            resolve(UpdateShopFeatureEntitlements::class)->handle(
                $actor,
                $shop,
                ['reports', 'expenses', 'workshop'],
            );
        } catch (FeatureEntitlementUpdateUnavailable $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(FeatureEntitlementUpdateUnavailable::class, $caughtException);
        $this->assertSame(
            'Feature entitlements could not be updated safely. Please retry.',
            $caughtException?->getMessage(),
        );
        $this->assertDatabaseCount('shop_features', 0, 'central');
        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_feature_updates_reject_an_unsafe_concrete_lock(): void
    {
        $this->configureFeatureLockStore();
        $centralConnection = DB::connection('central');
        $unsafeStore = new class($centralConnection, 'cache', '', 'cache_locks', [0, 100]) extends DatabaseStore
        {
            public function lock($name, $seconds = 0, $owner = null): ArrayLock
            {
                return new ArrayLock(new ArrayStore, $name, $seconds, $owner);
            }
        };
        $unsafeStore->setLockConnection($centralConnection);
        $this->replaceFeatureLockStore(new Repository($unsafeStore));
        $shop = Shop::factory()->create();
        $actor = PlatformUser::factory()->create();
        $caughtException = null;

        try {
            resolve(UpdateShopFeatureEntitlements::class)->handle(
                $actor,
                $shop,
                ['reports', 'expenses', 'workshop'],
            );
        } catch (FeatureEntitlementUpdateUnavailable $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(FeatureEntitlementUpdateUnavailable::class, $caughtException);
        $this->assertDatabaseCount('shop_features', 0, 'central');
        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_lost_lock_ownership_rolls_back_before_entitlements_or_audits_commit(): void
    {
        $this->configureFeatureLockStore();
        config()->set('cache.default', 'feature_entitlement_locks');
        app(CacheManager::class)->forgetDriver('feature_entitlement_locks');
        $shop = Shop::factory()->create();
        $actor = PlatformUser::factory()->create();
        config()->set(
            'database.connections.feature_lock_intruder',
            config('database.connections.central'),
        );
        DB::purge('feature_lock_intruder');
        $lockWasStolen = false;
        Event::listen(
            TransactionBeginning::class,
            static function (TransactionBeginning $event) use (&$lockWasStolen): void {
                if ($lockWasStolen || $event->connectionName !== 'central') {
                    return;
                }

                $updated = DB::connection('feature_lock_intruder')
                    ->table('cache_locks')
                    ->update(['owner' => 'intruding-owner']);

                if ($updated !== 1) {
                    throw new \RuntimeException('The test could not replace the active lock owner.');
                }

                $lockWasStolen = true;
            },
        );
        $caughtException = null;

        try {
            resolve(UpdateShopFeatureEntitlements::class)->handle(
                $actor,
                $shop,
                ['reports', 'expenses', 'workshop'],
            );
        } catch (FeatureEntitlementUpdateUnavailable $exception) {
            $caughtException = $exception;
        } finally {
            DB::purge('feature_lock_intruder');
        }

        $this->assertTrue($lockWasStolen, 'The test must replace the lock owner before the first entitlement read.');
        $this->assertInstanceOf(FeatureEntitlementUpdateUnavailable::class, $caughtException);
        $this->assertDatabaseHas('cache_locks', [
            'owner' => 'intruding-owner',
        ], 'central');
        $this->assertDatabaseCount('shop_features', 0, 'central');
        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_expired_lease_after_entitlement_read_rolls_back_before_commit(): void
    {
        $this->configureFeatureLockStore();
        $shop = Shop::factory()->create();
        $actor = PlatformUser::factory()->create();
        $leaseExpiredAfterRead = false;
        Event::listen(
            QueryExecuted::class,
            static function (QueryExecuted $event) use (&$leaseExpiredAfterRead): void {
                if ($leaseExpiredAfterRead
                    || $event->connectionName !== 'central'
                    || ! str_contains(strtolower($event->sql), 'shop_features')) {
                    return;
                }

                Carbon::setTestNow(Carbon::now()->addSeconds(31));
                $leaseExpiredAfterRead = true;
            },
        );
        $caughtException = null;

        try {
            resolve(UpdateShopFeatureEntitlements::class)->handle(
                $actor,
                $shop,
                ['reports', 'expenses', 'workshop'],
            );
        } catch (FeatureEntitlementUpdateUnavailable $exception) {
            $caughtException = $exception;
        } finally {
            Carbon::setTestNow();
        }

        $this->assertTrue($leaseExpiredAfterRead, 'The test must expire the lease after entitlements are read.');
        $this->assertInstanceOf(FeatureEntitlementUpdateUnavailable::class, $caughtException);
        $this->assertDatabaseCount('shop_features', 0, 'central');
        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_overlapping_feature_updates_are_serialized_across_processes(): void
    {
        $coordinationDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'feature-entitlement-concurrency-'.Str::uuid();
        File::ensureDirectoryExists($coordinationDirectory, 0700);

        $shop = Shop::factory()->create();
        $firstActor = PlatformUser::factory()->create(['name' => 'First administrator']);
        $secondActor = PlatformUser::factory()->create(['name' => 'Second administrator']);
        ShopFeature::query()->create([
            'shop_id' => $shop->getKey(),
            'module_key' => 'scripts',
            'enabled' => true,
        ]);

        $markers = [
            'first_read' => $coordinationDirectory.DIRECTORY_SEPARATOR.'first-read',
            'release_first' => $coordinationDirectory.DIRECTORY_SEPARATOR.'release-first',
            'second_waiting' => $coordinationDirectory.DIRECTORY_SEPARATOR.'second-waiting',
            'second_entered' => $coordinationDirectory.DIRECTORY_SEPARATOR.'second-entered',
        ];
        $firstProcess = $this->featureUpdateProcess(
            actorId: $firstActor->getKey(),
            shopId: $shop->getKey(),
            featureKeys: ['reports', 'expenses', 'workshop'],
            worker: 'first',
            markers: $markers,
        );
        $secondProcess = $this->featureUpdateProcess(
            actorId: $secondActor->getKey(),
            shopId: $shop->getKey(),
            featureKeys: ['reports', 'expenses', 'scripts', 'workshop'],
            worker: 'second',
            markers: $markers,
        );

        $firstReachedStaleRead = false;
        $secondReachedSerializationBoundary = false;
        $secondEnteredTransactionBeforeRelease = false;

        try {
            $firstProcess->start();
            $firstReachedStaleRead = $this->waitForMarker($markers['first_read'], $firstProcess);

            if ($firstReachedStaleRead) {
                $secondProcess->start();
                $secondReachedSerializationBoundary = $this->waitForMarker(
                    $markers['second_waiting'],
                    $secondProcess,
                );
                $secondEnteredTransactionBeforeRelease = $secondReachedSerializationBoundary
                    && $this->markerAppearsWithin(
                        $markers['second_entered'],
                        $secondProcess,
                        1.0,
                    );
            }
        } finally {
            File::put($markers['release_first'], 'release');

            if ($firstProcess->isStarted()) {
                $firstProcess->wait();
            }

            if ($secondProcess->isStarted()) {
                $secondProcess->wait();
            }
        }

        try {
            $this->assertTrue(
                $firstReachedStaleRead,
                'The first actor did not pause after reading entitlements. '
                    .$this->processFailure($firstProcess),
            );
            $this->assertTrue(
                $secondReachedSerializationBoundary,
                'The second actor did not wait on the per-shop serialization boundary. '
                    .$this->processFailure($secondProcess),
            );
            $this->assertFalse(
                $secondEnteredTransactionBeforeRelease,
                'The second actor read stale entitlements while the first actor was paused.',
            );
            $this->assertSame(0, $firstProcess->getExitCode(), $this->processFailure($firstProcess));
            $this->assertSame(0, $secondProcess->getExitCode(), $this->processFailure($secondProcess));
            $this->assertSame(
                ['changes' => 1],
                json_decode(trim($firstProcess->getOutput()), true, flags: JSON_THROW_ON_ERROR),
            );
            $this->assertSame(
                ['changes' => 1],
                json_decode(trim($secondProcess->getOutput()), true, flags: JSON_THROW_ON_ERROR),
            );
            $this->assertDatabaseHas('shop_features', [
                'shop_id' => $shop->getKey(),
                'module_key' => 'scripts',
                'enabled' => true,
            ], 'central');

            $transitions = $shop->lifecycleActivities()
                ->whereIn('event', [
                    ShopLifecycleEvent::FeatureDisabled,
                    ShopLifecycleEvent::FeatureEnabled,
                ])
                ->get()
                ->map(static fn ($activity): array => [
                    'actor_id' => $activity->platform_user_id,
                    'event' => $activity->event->value,
                    'module_key' => $activity->metadata['module_key'] ?? null,
                ])
                ->all();

            $this->assertEqualsCanonicalizing([
                [
                    'actor_id' => $firstActor->getKey(),
                    'event' => ShopLifecycleEvent::FeatureDisabled->value,
                    'module_key' => 'scripts',
                ],
                [
                    'actor_id' => $secondActor->getKey(),
                    'event' => ShopLifecycleEvent::FeatureEnabled->value,
                    'module_key' => 'scripts',
                ],
            ], $transitions);
        } finally {
            File::deleteDirectory($coordinationDirectory);
        }
    }

    /**
     * @param  list<string>  $featureKeys
     * @param  array<string, string>  $markers
     */
    private function featureUpdateProcess(
        int $actorId,
        string $shopId,
        array $featureKeys,
        string $worker,
        array $markers,
    ): Process {
        $process = new Process(
            [PHP_BINARY, '-r', $this->featureUpdateWorkerScript()],
            base_path(),
            [
                'APP_CONFIG_CACHE' => false,
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'CACHE_STORE' => 'array',
                'CENTRAL_DB_CONNECTION' => 'sqlite',
                'CENTRAL_DB_DATABASE' => $this->centralDatabasePath(),
                'DB_CONNECTION' => 'central',
                'DB_DATABASE' => $this->centralDatabasePath(),
                'DB_URL' => false,
                'FEATURE_ACTOR_ID' => (string) $actorId,
                'FEATURE_KEYS' => json_encode($featureKeys, JSON_THROW_ON_ERROR),
                'FEATURE_MARKER_FIRST_READ' => $markers['first_read'],
                'FEATURE_MARKER_RELEASE_FIRST' => $markers['release_first'],
                'FEATURE_MARKER_SECOND_ENTERED' => $markers['second_entered'],
                'FEATURE_MARKER_SECOND_WAITING' => $markers['second_waiting'],
                'FEATURE_SHOP_ID' => $shopId,
                'FEATURE_WORKER' => $worker,
            ],
        );
        $process->setTimeout(20);

        return $process;
    }

    private function featureUpdateWorkerScript(): string
    {
        return <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

            config()->set('database.connections.central', [
                'driver' => 'sqlite',
                'database' => getenv('CENTRAL_DB_DATABASE'),
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => 5000,
                'journal_mode' => 'WAL',
                'synchronous' => 'NORMAL',
                'transaction_mode' => 'DEFERRED',
            ]);
            Illuminate\Support\Facades\DB::purge('central');
            config()->set('cache.default', 'array');
            config()->set('cache.stores.feature_entitlement_locks', [
                'driver' => 'database',
                'connection' => 'central',
                'table' => 'cache',
                'lock_connection' => 'central',
                'lock_table' => 'cache_locks',
                'lock_lottery' => [0, 100],
            ]);
            $app->make(Illuminate\Cache\CacheManager::class)
                ->forgetDriver('feature_entitlement_locks');

            $worker = getenv('FEATURE_WORKER');

            Illuminate\Support\Facades\Event::listen(
                Illuminate\Database\Events\QueryExecuted::class,
                static function (Illuminate\Database\Events\QueryExecuted $event) use ($worker): void {
                    if ($event->connectionName !== 'central'
                        || ! str_starts_with(strtolower(trim($event->sql)), 'select')
                        || ! str_contains(strtolower($event->sql), 'from "shop_features"')) {
                        return;
                    }

                    if ($worker === 'second') {
                        file_put_contents(
                            getenv('FEATURE_MARKER_SECOND_ENTERED'),
                            'entered',
                            LOCK_EX,
                        );

                        return;
                    }

                    file_put_contents(getenv('FEATURE_MARKER_FIRST_READ'), 'read', LOCK_EX);
                    $deadline = microtime(true) + 10;

                    while (! is_file(getenv('FEATURE_MARKER_RELEASE_FIRST'))) {
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('Timed out waiting to release the first actor.');
                        }

                        usleep(10_000);
                    }
                },
            );

            $actor = App\Models\Central\PlatformUser::query()
                ->findOrFail((int) getenv('FEATURE_ACTOR_ID'));
            $shop = App\Models\Central\Shop::query()
                ->findOrFail(getenv('FEATURE_SHOP_ID'));
            $featureKeys = json_decode(
                getenv('FEATURE_KEYS'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            if ($worker === 'second') {
                file_put_contents(
                    getenv('FEATURE_MARKER_SECOND_WAITING'),
                    'waiting',
                    LOCK_EX,
                );
            }

            $changes = $app->make(App\Actions\Tenancy\UpdateShopFeatureEntitlements::class)
                ->handle($actor, $shop, $featureKeys);

            echo json_encode(['changes' => $changes], JSON_THROW_ON_ERROR);
            PHP;
    }

    private function waitForMarker(string $marker, Process $process): bool
    {
        return $this->waitForAnyMarker([$marker], $process) === $marker;
    }

    private function markerAppearsWithin(
        string $marker,
        Process $process,
        float $seconds,
    ): bool {
        $deadline = microtime(true) + $seconds;

        while ($process->isRunning() && microtime(true) < $deadline) {
            if (is_file($marker)) {
                return true;
            }

            usleep(10_000);
        }

        return is_file($marker);
    }

    /** @param list<string> $markers */
    private function waitForAnyMarker(array $markers, Process $process): ?string
    {
        $deadline = microtime(true) + 5;

        while ($process->isRunning() && microtime(true) < $deadline) {
            foreach ($markers as $marker) {
                if (is_file($marker)) {
                    return $marker;
                }
            }

            usleep(10_000);
        }

        foreach ($markers as $marker) {
            if (is_file($marker)) {
                return $marker;
            }
        }

        return null;
    }

    private function processFailure(Process $process): string
    {
        return trim($process->getErrorOutput().' '.$process->getOutput());
    }

    private function configureFeatureLockStore(): void
    {
        config()->set('cache.stores.feature_entitlement_locks', [
            'driver' => 'database',
            'connection' => 'central',
            'table' => 'cache',
            'lock_connection' => 'central',
            'lock_table' => 'cache_locks',
            'lock_lottery' => [0, 100],
        ]);
        app(CacheManager::class)->forgetDriver('feature_entitlement_locks');
    }

    private function replaceFeatureLockStore(Repository $repository): void
    {
        $cacheManager = app(CacheManager::class);
        $storesProperty = new ReflectionProperty($cacheManager, 'stores');
        $stores = $storesProperty->getValue($cacheManager);
        $stores['feature_entitlement_locks'] = $repository;
        $storesProperty->setValue($cacheManager, $stores);
    }

    private function featureLockName(Shop $shop): string
    {
        return 'shop-feature-entitlements:'.$shop->getKey();
    }

    /** @return array<string, array{array<string, mixed>|null}> */
    public static function unsafeFeatureLockStores(): array
    {
        return [
            'missing store' => [null],
            'process-local driver' => [[
                'driver' => 'array',
                'connection' => 'central',
                'lock_connection' => 'central',
            ]],
            'tenant cache connection' => [[
                'driver' => 'database',
                'connection' => 'tenant',
                'table' => 'cache',
                'lock_connection' => 'central',
                'lock_table' => 'cache_locks',
            ]],
            'tenant lock connection' => [[
                'driver' => 'database',
                'connection' => 'central',
                'table' => 'cache',
                'lock_connection' => 'tenant',
                'lock_table' => 'cache_locks',
            ]],
            'implicit lock connection' => [[
                'driver' => 'database',
                'connection' => 'central',
                'table' => 'cache',
                'lock_connection' => null,
                'lock_table' => 'cache_locks',
            ]],
        ];
    }
}
