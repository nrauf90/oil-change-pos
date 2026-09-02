<?php

namespace Tests\Feature\Platform;

use App\Enums\ShopLifecycleEvent;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopFeature;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class PlatformFeatureEntitlementConcurrencyTest extends PlatformTestCase
{
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
        $cachePath = $coordinationDirectory.DIRECTORY_SEPARATOR.'cache';
        $lockPath = $coordinationDirectory.DIRECTORY_SEPARATOR.'locks';
        $firstProcess = $this->featureUpdateProcess(
            actorId: $firstActor->getKey(),
            shopId: $shop->getKey(),
            featureKeys: ['reports', 'expenses', 'workshop'],
            worker: 'first',
            markers: $markers,
            cachePath: $cachePath,
            lockPath: $lockPath,
        );
        $secondProcess = $this->featureUpdateProcess(
            actorId: $secondActor->getKey(),
            shopId: $shop->getKey(),
            featureKeys: ['reports', 'expenses', 'scripts', 'workshop'],
            worker: 'second',
            markers: $markers,
            cachePath: $cachePath,
            lockPath: $lockPath,
        );

        $firstReachedStaleRead = false;
        $secondReachedSerializationBoundary = false;
        $secondEnteredTransactionBeforeRelease = false;

        try {
            $firstProcess->start();
            $firstReachedStaleRead = $this->waitForMarker($markers['first_read'], $firstProcess);

            if ($firstReachedStaleRead) {
                $secondProcess->start();
                $observedMarker = $this->waitForAnyMarker([
                    $markers['second_waiting'],
                    $markers['second_entered'],
                ], $secondProcess);
                $secondReachedSerializationBoundary = $observedMarker === $markers['second_waiting'];
                $secondEnteredTransactionBeforeRelease = is_file($markers['second_entered']);
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
        string $cachePath,
        string $lockPath,
    ): Process {
        $process = new Process(
            [PHP_BINARY, '-r', $this->featureUpdateWorkerScript()],
            base_path(),
            [
                'APP_CONFIG_CACHE' => false,
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'CACHE_STORE' => 'file',
                'CENTRAL_DB_CONNECTION' => 'sqlite',
                'CENTRAL_DB_DATABASE' => $this->centralDatabasePath(),
                'DB_CONNECTION' => 'central',
                'DB_DATABASE' => $this->centralDatabasePath(),
                'DB_URL' => false,
                'FEATURE_ACTOR_ID' => (string) $actorId,
                'FEATURE_CACHE_PATH' => $cachePath,
                'FEATURE_KEYS' => json_encode($featureKeys, JSON_THROW_ON_ERROR),
                'FEATURE_LOCK_PATH' => $lockPath,
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
            config()->set('cache.default', 'file');
            config()->set('cache.stores.file.path', getenv('FEATURE_CACHE_PATH'));
            config()->set('cache.stores.file.lock_path', getenv('FEATURE_LOCK_PATH'));
            Illuminate\Support\Facades\Cache::purge('file');

            $worker = getenv('FEATURE_WORKER');

            if ($worker === 'second') {
                $cacheStore = new class(
                    $app->make(Illuminate\Filesystem\Filesystem::class),
                    getenv('FEATURE_CACHE_PATH'),
                    getenv('FEATURE_MARKER_SECOND_WAITING'),
                ) extends Illuminate\Cache\FileStore {
                    public function __construct(
                        Illuminate\Filesystem\Filesystem $files,
                        string $directory,
                        private readonly string $waitingMarker,
                    ) {
                        parent::__construct($files, $directory);
                    }

                    public function lock($name, $seconds = 0, $owner = null)
                    {
                        $directory = $this->lockDirectory ?? $this->directory;
                        $this->ensureCacheDirectoryExists($directory);
                        $store = new Illuminate\Cache\FileStore($this->files, $directory);

                        return new class(
                            $store,
                            "file-store-lock:{$name}",
                            $seconds,
                            $owner,
                            $this->waitingMarker,
                        ) extends Illuminate\Cache\FileLock {
                            public function __construct(
                                $store,
                                $name,
                                $seconds,
                                $owner,
                                private readonly string $waitingMarker,
                            ) {
                                parent::__construct($store, $name, $seconds, $owner);
                            }

                            public function acquire()
                            {
                                $acquired = parent::acquire();

                                if (! $acquired) {
                                    file_put_contents($this->waitingMarker, 'waiting', LOCK_EX);
                                }

                                return $acquired;
                            }
                        };
                    }
                };
                $cacheStore->setLockDirectory(getenv('FEATURE_LOCK_PATH'));
                Illuminate\Support\Facades\Cache::swap(
                    new Illuminate\Cache\Repository($cacheStore),
                );
            }

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
            $changes = $app->make(App\Actions\Tenancy\UpdateShopFeatureEntitlements::class)
                ->handle($actor, $shop, $featureKeys);

            echo json_encode(['changes' => $changes], JSON_THROW_ON_ERROR);
            PHP;
    }

    private function waitForMarker(string $marker, Process $process): bool
    {
        return $this->waitForAnyMarker([$marker], $process) === $marker;
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
}
