<?php

namespace Tests\Feature\Tenancy;

use App\Jobs\Concerns\RunsInTenantContext;
use App\Models\Central\Shop;
use App\Models\Item;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TenantQueueIsolationTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    private TenantConnectionManager $manager;

    private Shop $shopA;

    private Shop $shopB;

    private string $tenantRoot;

    protected function setUp(): void
    {
        parent::setUp();

        TenantQueueProbeJob::$observations = [];
        $this->tenantRoot = $this->newTemporaryDirectory('tenant-queue-isolation-');
        $centralDatabase = $this->tenantRoot.DIRECTORY_SEPARATOR.'central.sqlite';

        if (File::put($centralDatabase, '') === false) {
            throw new RuntimeException('Unable to create the central test database.');
        }

        config()->set('database.default', 'central');
        config()->set('database.tenant_sqlite_root', $this->tenantRoot);
        config()->set(
            'database.tenant_attestation_lock_path',
            $this->tenantRoot.DIRECTORY_SEPARATOR.'attestation-locks',
        );
        config()->set('queue.default', 'sync');
        $this->configureCentralDatabase($centralDatabase);
        DB::purge('tenant');
        $this->migrateCentralDatabase();

        $this->manager = app(TenantConnectionManager::class);
        $this->shopA = $this->createActiveTenant('queue-shop-a');
        $this->shopB = $this->createActiveTenant('queue-shop-b');
    }

    protected function tearDown(): void
    {
        try {
            $this->manager->disconnect();
            DB::purge('central');

            foreach ($this->temporaryDirectories as $temporaryDirectory) {
                File::deleteDirectory($temporaryDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    public function test_serialized_jobs_with_colliding_ids_run_in_their_tenant_and_cleanup_between_jobs(): void
    {
        $itemAId = $this->seedItem($this->shopA, 'Tenant A queued item');
        $itemBId = $this->seedItem($this->shopB, 'Tenant B queued item');
        $jobA = new TenantQueueProbeJob(
            $this->shopA,
            $itemAId,
            'Tenant A queued item',
            'Tenant A job completed',
        );
        $jobB = new TenantQueueProbeJob(
            $this->shopB,
            $itemBId,
            'Tenant B queued item',
            'Tenant B job completed',
        );

        $this->assertSame($itemAId, $itemBId);
        $this->assertSerializedIdentityContainsOnlyTheTenantId($jobA, $this->shopA);
        $this->assertSerializedIdentityContainsOnlyTheTenantId($jobB, $this->shopB);

        Bus::dispatchSync($jobA);
        $this->assertTenantStateIsRevoked();
        Bus::dispatchSync($jobB);
        $this->assertTenantStateIsRevoked();

        $this->assertSame([
            [(string) $this->shopA->getKey(), 'Tenant A queued item'],
            [(string) $this->shopB->getKey(), 'Tenant B queued item'],
        ], TenantQueueProbeJob::$observations);
        $this->assertSame('Tenant A job completed', $this->tenantItemName($this->shopA, $itemAId));
        $this->assertSame('Tenant B job completed', $this->tenantItemName($this->shopB, $itemBId));
    }

    public function test_failed_job_cleans_up_before_the_next_tenant_job_runs(): void
    {
        $itemAId = $this->seedItem($this->shopA, 'Tenant A failing item');
        $itemBId = $this->seedItem($this->shopB, 'Tenant B recovery item');

        try {
            Bus::dispatchSync(new TenantQueueProbeJob(
                $this->shopA,
                $itemAId,
                'Tenant A failing item',
                'Tenant A must remain unchanged',
                failAfterRead: true,
            ));
            $this->fail('The tenant queue probe exception was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced tenant queue failure.', $exception->getMessage());
        }

        $this->assertTenantStateIsRevoked();
        $this->assertSame('Tenant A failing item', $this->tenantItemName($this->shopA, $itemAId));

        Bus::dispatchSync(new TenantQueueProbeJob(
            $this->shopB,
            $itemBId,
            'Tenant B recovery item',
            'Tenant B recovered after failure',
        ));

        $this->assertTenantStateIsRevoked();
        $this->assertSame([
            [(string) $this->shopA->getKey(), 'Tenant A failing item'],
            [(string) $this->shopB->getKey(), 'Tenant B recovery item'],
        ], TenantQueueProbeJob::$observations);
        $this->assertSame('Tenant A failing item', $this->tenantItemName($this->shopA, $itemAId));
        $this->assertSame('Tenant B recovered after failure', $this->tenantItemName($this->shopB, $itemBId));
    }

    private function createActiveTenant(string $slug): Shop
    {
        $shop = Shop::registerForProvisioning(
            name: str($slug)->headline()->toString(),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $this->tenantRoot.DIRECTORY_SEPARATOR.$slug.'.sqlite',
        );
        $this->createMigratedTenantDatabase($shop);
        $shop->markActive();

        return $shop;
    }

    private function seedItem(Shop $shop, string $name): int
    {
        return $this->manager->within(
            $shop,
            static fn (): int => Item::factory()->create(['name' => $name])->getKey(),
        );
    }

    private function tenantItemName(Shop $shop, int $itemId): string
    {
        return $this->manager->within(
            $shop,
            static fn (): string => Item::query()->findOrFail($itemId)->name,
        );
    }

    private function assertSerializedIdentityContainsOnlyTheTenantId(TenantQueueProbeJob $job, Shop $shop): void
    {
        $serializedJob = serialize($job);

        $this->assertStringContainsString((string) $shop->getKey(), $serializedJob);
        $this->assertStringNotContainsString((string) $shop->database_name, $serializedJob);
        $this->assertStringNotContainsString(Shop::class, $serializedJob);
    }

    private function assertTenantStateIsRevoked(): void
    {
        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
        $this->assertArrayNotHasKey('tenant', DB::getConnections());
    }

    private function newTemporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.Str::uuid();

        if (! File::makeDirectory($directory, 0700, true)) {
            throw new RuntimeException('Unable to create tenant queue isolation test directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}

final class TenantQueueProbeJob implements ShouldQueue
{
    use Queueable;
    use RunsInTenantContext;

    /** @var list<array{string, string}> */
    public static array $observations = [];

    public function __construct(
        Shop $shop,
        private readonly int $itemId,
        private readonly string $expectedName,
        private readonly string $updatedName,
        private readonly bool $failAfterRead = false,
    ) {
        $this->runInTenantContext($shop);
    }

    public function handle(TenantContext $context): void
    {
        $item = Item::query()->findOrFail($this->itemId);
        self::$observations[] = [$context->id(), $item->name];

        if ($item->name !== $this->expectedName) {
            throw new RuntimeException('The queued job read a colliding item from another tenant.');
        }

        if ($this->failAfterRead) {
            throw new RuntimeException('Forced tenant queue failure.');
        }

        $item->update(['name' => $this->updatedName]);
    }
}
