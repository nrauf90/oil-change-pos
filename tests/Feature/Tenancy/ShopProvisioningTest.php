<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Tenancy\ProvisionShop;
use App\Data\ProvisionShopData;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Exceptions\TenantProvisioningException;
use App\Models\Central\Shop;
use App\Models\Central\ShopOwner;
use App\Models\Item;
use App\Models\ModuleSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Modules\ModuleRegistry;
use App\Tenancy\Provisioning\DatabaseProvisioner;
use App\Tenancy\Provisioning\NullTenantProvisioningHook;
use App\Tenancy\Provisioning\TenantProvisioningCheckpoint;
use App\Tenancy\Provisioning\TenantProvisioningHook;
use App\Tenancy\Provisioning\TenantProvisioningInterrupted;
use App\Tenancy\Provisioning\TenantProvisioningLease;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ShopProvisioningTest extends TestCase
{
    private string $databaseRoot;

    private string $tenantRoot;

    private string $centralDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'shop-provisioning-'.Str::uuid();
        $this->tenantRoot = $this->databaseRoot.DIRECTORY_SEPARATOR.'tenants';
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists($this->tenantRoot, 0700);

        $this->centralDatabase = $this->databaseRoot.DIRECTORY_SEPARATOR.'central.sqlite';
        $this->createEmptyDatabase($this->centralDatabase);

        config()->set('database.default', 'central');
        config()->set('database.tenant_sqlite_root', $this->tenantRoot);
        config()->set(
            'database.tenant_attestation_lock_path',
            $this->databaseRoot.DIRECTORY_SEPARATOR.'attestation-locks',
        );
        config()->set('cache.default', 'database');
        config()->set('cache.stores.database.connection', 'central');
        config()->set('cache.stores.database.lock_connection', 'central');
        $this->configureCentralDatabase($this->centralDatabase);
        DB::purge('tenant');
        $this->migrateCentralDatabase();
    }

    protected function tearDown(): void
    {
        try {
            app(TenantConnectionManager::class)->disconnect();
            DB::purge('central');
        } finally {
            (new Filesystem)->deleteDirectory($this->databaseRoot);
            parent::tearDown();
        }
    }

    protected function usesDefaultTenantContext(): bool
    {
        return false;
    }

    public function test_new_sqlite_shop_creates_and_activates_an_isolated_tenant(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'north-workshop.sqlite';
        $password = 'temporary-owner-password';

        $shop = app(ProvisionShop::class)->handle(new ProvisionShopData(
            name: 'North Workshop',
            slug: 'north-workshop',
            databaseDriver: 'sqlite',
            databaseName: $database,
            ownerName: 'North Owner',
            ownerUsername: 'north-owner',
            ownerEmail: 'owner@example.test',
            temporaryOwnerPassword: $password,
            initialFeatureKeys: ['scripts', 'workshop'],
        ));

        $this->assertFileExists($database);
        $this->assertSame(ShopStatus::Active, $shop->status);
        $this->assertNotNull($shop->provisioned_at);
        $this->assertDatabaseHas('shop_owners', [
            'shop_id' => $shop->getKey(),
            'name' => 'North Owner',
            'username' => 'north-owner',
            'email' => 'owner@example.test',
            'is_active' => true,
        ], 'central');
        $this->assertDatabaseHas('shop_features', [
            'shop_id' => $shop->getKey(),
            'module_key' => 'scripts',
            'enabled' => true,
        ], 'central');
        $this->assertDatabaseHas('shop_health_snapshots', [
            'shop_id' => $shop->getKey(),
            'migration_status' => 'current',
        ], 'central');

        $activities = $shop->lifecycleActivities()->oldest('occurred_at')->get();

        $this->assertSame([
            ShopLifecycleEvent::ProvisioningStarted,
            ShopLifecycleEvent::TenantInstallationAuthorized,
            ShopLifecycleEvent::ProvisioningSucceeded,
        ], $activities->pluck('event')->all());
        $this->assertSame([
            'database_driver' => 'sqlite',
            'reason_code' => 'exclusive_create',
            'target_fingerprint' => $shop->database_target_fingerprint,
        ], $activities->firstWhere('event', ShopLifecycleEvent::TenantInstallationAuthorized)?->metadata);

        app(TenantConnectionManager::class)->within($shop->fresh(), function () use ($shop, $password): void {
            $owner = User::query()->where('username', 'north-owner')->firstOrFail();

            $this->assertTrue(Hash::check($password, $owner->password));
            $this->assertSame(RoleEnum::Admin, $owner->role());
            $this->assertSame(1, User::query()->count());
            $this->assertSame(count(PermissionEnum::cases()), Permission::query()->count());
            $this->assertSame(count(RoleEnum::cases()), Role::query()->count());
            $this->assertSame(app(ModuleRegistry::class)->all()->count(), ModuleSetting::query()->count());
            $this->assertGreaterThan(0, Item::query()->count());
            $this->assertGreaterThan(0, VehicleMake::query()->count());
            $this->assertGreaterThan(0, VehicleModel::query()->count());
            $this->assertSame(
                count(glob(database_path('migrations/tenant').DIRECTORY_SEPARATOR.'*.php') ?: []),
                DB::connection('tenant')->table('migrations')->count(),
            );
            $this->assertSame(1, DB::connection('tenant')->table('migrations')
                ->where('migration', '2026_09_02_042731_create_tenant_installations_table')
                ->count());
            $this->assertDatabaseHas('tenant_installations', [
                'id' => 1,
                'shop_id' => $shop->getKey(),
                'target_fingerprint' => $shop->database_target_fingerprint,
                'attestation_hmac' => $shop->databaseAttestationHmac(),
            ], 'tenant');
        });

        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
        $this->assertSame('central', config('database.default'));
    }

    public function test_retry_after_create_before_receipt_is_ambiguous_and_preserves_the_target(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'ambiguous.sqlite';
        $this->interruptAt(TenantProvisioningCheckpoint::AfterPhysicalCreateBeforeAuthorization);

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('The simulated interruption should fail the first attempt.');
        } catch (TenantProvisioningInterrupted) {
        }

        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $this->assertSame(ShopStatus::Provisioning, $shop->status);
        $this->assertFileExists($database);
        $size = filesize($database);

        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);

        try {
            app(ProvisionShop::class)->retry($shop, 'temporary-owner-password');
            $this->fail('An unreceipted physical target must remain ambiguous.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('TARGET_BOOTSTRAP_AMBIGUOUS', $exception->errorCode);
        }

        $this->assertFileExists($database);
        $this->assertSame($size, filesize($database));
        $this->assertSame(ShopStatus::Failed, $shop->fresh()->status);
        $this->assertDatabaseMissing('shop_lifecycle_activities', [
            'shop_id' => $shop->getKey(),
            'event' => ShopLifecycleEvent::TenantInstallationAuthorized->value,
        ], 'central');
    }

    public function test_process_interruption_after_receipt_resumes_idempotently(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'receipted.sqlite';
        $this->app->instance(TenantProvisioningHook::class, new class implements TenantProvisioningHook
        {
            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($checkpoint === TenantProvisioningCheckpoint::AfterAuthorization) {
                    throw new TenantProvisioningInterrupted;
                }
            }
        });

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('The provisioning hook should interrupt after authorization.');
        } catch (TenantProvisioningInterrupted) {
        }

        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $this->assertSame(ShopStatus::Provisioning, $shop->status);
        $this->assertFileExists($database);
        $this->assertSame(1, $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::TenantInstallationAuthorized->value)
            ->count());

        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);
        $shop = app(ProvisionShop::class)->retry($shop, 'temporary-owner-password');

        $this->assertSame(ShopStatus::Active, $shop->status);
        $this->assertSame(1, $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::TenantInstallationAuthorized->value)
            ->count());
        app(TenantConnectionManager::class)->within($shop, function (): void {
            $this->assertSame(1, DB::connection('tenant')->table('migrations')
                ->where('migration', '2026_09_02_042731_create_tenant_installations_table')
                ->count());
            $this->assertSame(1, DB::connection('tenant')->table('tenant_installations')->count());
        });
    }

    #[DataProvider('resumableMarkerCheckpoints')]
    public function test_marker_bootstrap_interruptions_resume_without_duplicate_infrastructure(
        TenantProvisioningCheckpoint $interruptionPoint,
        int $expectedMigrationRowsBeforeRetry,
        int $expectedMarkerRowsBeforeRetry,
    ): void {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'marker-resume.sqlite';
        $this->app->instance(TenantProvisioningHook::class, new class($interruptionPoint) implements TenantProvisioningHook
        {
            public function __construct(
                private readonly TenantProvisioningCheckpoint $interruptionPoint,
            ) {}

            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($checkpoint === $this->interruptionPoint) {
                    throw new TenantProvisioningInterrupted;
                }
            }
        });

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('The marker checkpoint should interrupt provisioning.');
        } catch (TenantProvisioningInterrupted) {
        }

        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $this->assertSame(ShopStatus::Provisioning, $shop->status);
        $this->assertSame(1, $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::TenantInstallationAuthorized->value)
            ->count());
        $pdo = new PDO('sqlite:'.$database);
        $migrationCount = $pdo->query(
            "SELECT COUNT(*) FROM migrations WHERE migration = '2026_09_02_042731_create_tenant_installations_table'",
        )?->fetchColumn();
        $markerCount = $pdo->query('SELECT COUNT(*) FROM tenant_installations')?->fetchColumn();
        $pdo = null;
        $this->assertSame($expectedMigrationRowsBeforeRetry, (int) $migrationCount);
        $this->assertSame($expectedMarkerRowsBeforeRetry, (int) $markerCount);
        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
        $this->assertSame([], array_filter(
            array_keys((array) config('database.connections')),
            static fn (string $name): bool => str_starts_with($name, 'tenant_installer_'),
        ));

        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);
        $shop = app(ProvisionShop::class)->retry($shop, 'temporary-owner-password');

        $this->assertSame(ShopStatus::Active, $shop->status);
        app(TenantConnectionManager::class)->within($shop, function (): void {
            $this->assertSame(1, DB::connection('tenant')->table('migrations')
                ->where('migration', '2026_09_02_042731_create_tenant_installations_table')
                ->count());
            $this->assertSame(1, DB::connection('tenant')->table('tenant_installations')->count());
        });
    }

    /** @return array<string, array{TenantProvisioningCheckpoint, int, int}> */
    public static function resumableMarkerCheckpoints(): array
    {
        return [
            'after marker DDL before migration log' => [
                TenantProvisioningCheckpoint::AfterMarkerTableDdlBeforeLog,
                0,
                0,
            ],
            'after marker migration log before row' => [
                TenantProvisioningCheckpoint::AfterMarkerMigrationLoggedBeforeRow,
                1,
                0,
            ],
            'after marker row before manager connection' => [
                TenantProvisioningCheckpoint::AfterMarkerRowBeforeManagerConnection,
                1,
                1,
            ],
        ];
    }

    public function test_a_tenant_connection_is_never_established_before_the_exact_marker_exists(): void
    {
        $tenantConnectionObserved = false;
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use (
            &$tenantConnectionObserved,
        ): void {
            if ($event->connection->getName() !== 'tenant') {
                return;
            }

            $tenantConnectionObserved = true;
            $this->assertTrue($event->connection->getSchemaBuilder()->hasTable('tenant_installations'));
            $this->assertSame(1, $event->connection->table('tenant_installations')->count());
        });

        app(ProvisionShop::class)->handle($this->provisioningData(
            $this->tenantRoot.DIRECTORY_SEPARATOR.'connection-order.sqlite',
        ));

        $this->assertTrue($tenantConnectionObserved);
    }

    public function test_unknown_initial_feature_is_rejected_before_any_write(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'unknown-feature.sqlite';

        try {
            app(ProvisionShop::class)->handle(new ProvisionShopData(
                name: 'Test Workshop',
                slug: 'test-workshop',
                databaseDriver: 'sqlite',
                databaseName: $database,
                ownerName: 'Test Owner',
                ownerUsername: 'test-owner',
                ownerEmail: null,
                temporaryOwnerPassword: 'temporary-owner-password',
                initialFeatureKeys: ['unknown-feature'],
            ));
            $this->fail('Unknown features must fail before registration.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('UNKNOWN_FEATURE', $exception->errorCode);
        }

        $this->assertSame(0, Shop::query()->count());
        $this->assertFileDoesNotExist($database);
    }

    public function test_existing_unmarked_database_is_rejected_without_writes_or_deletion(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'unmarked.sqlite';
        $this->createEmptyDatabase($database);
        $before = hash_file('sha256', $database);

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('An unmarked existing target must not be adopted.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('TARGET_ALREADY_EXISTS', $exception->errorCode);
        }

        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $this->assertSame(ShopStatus::Failed, $shop->status);
        $this->assertFileExists($database);
        $this->assertSame($before, hash_file('sha256', $database));
        $this->assertDatabaseMissing('shop_lifecycle_activities', [
            'shop_id' => $shop->getKey(),
            'event' => ShopLifecycleEvent::TenantInstallationAuthorized->value,
        ], 'central');
    }

    public function test_exact_existing_marker_is_sufficient_proof_for_idempotent_resume(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'exact-marker.sqlite';
        $shop = Shop::registerForProvisioning(
            name: 'Exact Marker Workshop',
            slug: 'exact-marker-workshop',
            databaseDriver: 'sqlite',
            databaseName: $database,
        );
        ShopOwner::query()->create([
            'shop_id' => $shop->getKey(),
            'name' => 'Exact Owner',
            'username' => 'exact-owner',
            'email' => null,
            'is_active' => true,
        ]);
        $this->createTenantDatabase($shop);

        $shop = app(ProvisionShop::class)->retry($shop, 'temporary-owner-password');

        $this->assertSame(ShopStatus::Active, $shop->status);
        $this->assertSame(0, $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::TenantInstallationAuthorized->value)
            ->count());
        app(TenantConnectionManager::class)->within($shop, function (): void {
            $this->assertSame(1, DB::connection('tenant')->table('migrations')
                ->where('migration', '2026_09_02_042731_create_tenant_installations_table')
                ->count());
            $this->assertSame(1, User::query()->where('username', 'exact-owner')->count());
        });
    }

    public function test_logged_marker_migration_without_marker_table_is_rejected_without_writes(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'inconsistent-marker.sqlite';
        $this->createEmptyDatabase($database);
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR NOT NULL, batch INTEGER NOT NULL)');
        $statement = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
        $statement->execute(['2026_09_02_042731_create_tenant_installations_table', 1]);
        $pdo = null;
        $before = hash_file('sha256', $database);

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('An inconsistent marker state requires operator review.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('TENANT_MARKER_INCONSISTENT', $exception->errorCode);
        }

        $this->assertSame($before, hash_file('sha256', $database));
        $this->assertFileExists($database);
    }

    public function test_duplicate_marker_rows_are_rejected_without_repair_or_deletion(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'duplicate-marker.sqlite';
        $this->createEmptyDatabase($database);
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR NOT NULL, batch INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE tenant_installations (
            id INTEGER PRIMARY KEY,
            shop_id VARCHAR(36) NOT NULL UNIQUE,
            target_fingerprint CHAR(64) NOT NULL,
            attestation_hmac CHAR(64) NOT NULL,
            connection_nonce CHAR(64) NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');
        $migration = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
        $migration->execute(['2026_09_02_042731_create_tenant_installations_table', 1]);
        $marker = $pdo->prepare('INSERT INTO tenant_installations (
            id, shop_id, target_fingerprint, attestation_hmac, connection_nonce, created_at, updated_at
        ) VALUES (?, ?, ?, ?, NULL, NULL, NULL)');
        $marker->execute([1, '11111111-1111-4111-8111-111111111111', str_repeat('a', 64), str_repeat('b', 64)]);
        $marker->execute([2, '22222222-2222-4222-8222-222222222222', str_repeat('c', 64), str_repeat('d', 64)]);
        $pdo = null;
        $before = hash_file('sha256', $database);

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('Duplicate marker rows must never be repaired automatically.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('TENANT_MARKER_CONFLICT', $exception->errorCode);
        }

        $this->assertSame($before, hash_file('sha256', $database));
        $this->assertFileExists($database);
    }

    public function test_active_retry_is_an_idempotent_no_op(): void
    {
        $shop = app(ProvisionShop::class)->handle($this->provisioningData(
            $this->tenantRoot.DIRECTORY_SEPARATOR.'active-no-op.sqlite',
        ));
        $before = $this->tenantProvisioningSnapshot($shop);
        $activityCount = $shop->lifecycleActivities()->count();

        $retriedShop = app(ProvisionShop::class)->retry($shop, 'replacement-password');

        $this->assertSame(ShopStatus::Active, $retriedShop->status);
        $this->assertSame($activityCount, $retriedShop->lifecycleActivities()->count());
        $this->assertSame($before, $this->tenantProvisioningSnapshot($retriedShop));
    }

    public function test_duplicate_slug_is_rejected_before_a_second_target_is_created(): void
    {
        $firstDatabase = $this->tenantRoot.DIRECTORY_SEPARATOR.'first-slug.sqlite';
        $secondDatabase = $this->tenantRoot.DIRECTORY_SEPARATOR.'second-slug.sqlite';
        $firstShop = app(ProvisionShop::class)->handle($this->provisioningData($firstDatabase));

        try {
            app(ProvisionShop::class)->handle(new ProvisionShopData(
                name: 'Duplicate Slug Workshop',
                slug: 'test-workshop',
                databaseDriver: 'sqlite',
                databaseName: $secondDatabase,
                ownerName: 'Another Owner',
                ownerUsername: 'another-owner',
                ownerEmail: null,
                temporaryOwnerPassword: 'temporary-owner-password',
            ));
            $this->fail('A duplicate slug must not create another shop target.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('SHOP_SLUG_ALREADY_EXISTS', $exception->errorCode);
        }

        $this->assertSame(ShopStatus::Active, $firstShop->fresh()->status);
        $this->assertSame(1, Shop::query()->count());
        $this->assertFileDoesNotExist($secondDatabase);
    }

    public function test_retry_requires_a_secret_only_when_the_owner_is_absent(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'owner-missing.sqlite';
        $this->interruptAt(TenantProvisioningCheckpoint::AfterTenantMigrations);

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('The hook should interrupt before owner creation.');
        } catch (TenantProvisioningInterrupted) {
        }

        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);

        try {
            app(ProvisionShop::class)->retry($shop);
            $this->fail('A missing tenant owner requires a fresh secret.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('OWNER_PASSWORD_REQUIRED', $exception->errorCode);
        }

        $this->assertSame(ShopStatus::Failed, $shop->fresh()->status);
        $shop = app(ProvisionShop::class)->retry($shop->fresh(), 'new-temporary-password');
        $this->assertSame(ShopStatus::Active, $shop->status);
    }

    public function test_retry_after_partial_migration_failure_resumes_without_duplicates(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'partial-migration.sqlite';
        $this->app->instance(TenantProvisioningHook::class, new class implements TenantProvisioningHook
        {
            private int $migrationsReached = 0;

            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($checkpoint === TenantProvisioningCheckpoint::BeforeTenantMigrationRun
                    && ++$this->migrationsReached === 2) {
                    throw new RuntimeException('Simulated migration failure with sensitive driver details.');
                }
            }
        });

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('The migration hook should fail after one migration was logged.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('TENANT_MIGRATION_FAILED', $exception->errorCode);
        }

        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $this->assertSame(ShopStatus::Failed, $shop->status);
        $this->assertFileExists($database);
        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);

        $shop = app(ProvisionShop::class)->retry($shop, 'temporary-owner-password');

        $this->assertSame(ShopStatus::Active, $shop->status);
        app(TenantConnectionManager::class)->within($shop, function (): void {
            $migrationCount = DB::connection('tenant')->table('migrations')->count();
            $uniqueMigrationCount = DB::connection('tenant')->table('migrations')
                ->distinct()
                ->count('migration');

            $this->assertSame($migrationCount, $uniqueMigrationCount);
            $this->assertSame(1, User::query()->where('username', 'test-owner')->count());
            $this->assertSame(count(PermissionEnum::cases()), Permission::query()->count());
            $this->assertSame(count(RoleEnum::cases()), Role::query()->count());
            $this->assertSame(app(ModuleRegistry::class)->all()->count(), ModuleSetting::query()->count());
        });
    }

    public function test_retry_preserves_an_existing_owner_identity_hash_and_active_state(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'owner-preserved.sqlite';
        $this->interruptAt(TenantProvisioningCheckpoint::BeforeActivation);

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('The hook should interrupt after tenant writes.');
        } catch (TenantProvisioningInterrupted) {
        }

        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $preservedHash = Hash::make('operator-password');
        $ownerId = app(TenantConnectionManager::class)->within($shop, function () use ($preservedHash): int {
            $owner = User::query()->where('username', 'test-owner')->firstOrFail();
            $owner->forceFill([
                'name' => 'Operator Edited',
                'password' => $preservedHash,
                'is_active' => false,
            ])->save();

            return $owner->id;
        });
        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);

        $shop = app(ProvisionShop::class)->retry($shop);

        app(TenantConnectionManager::class)->within($shop, function () use ($ownerId, $preservedHash): void {
            $owner = User::query()->where('username', 'test-owner')->firstOrFail();
            $this->assertSame($ownerId, $owner->id);
            $this->assertSame('Operator Edited', $owner->name);
            $this->assertSame($preservedHash, $owner->password);
            $this->assertFalse($owner->is_active);
        });
    }

    public function test_activation_health_status_and_success_audit_commit_atomically(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'activation-atomic.sqlite';
        $this->app->instance(TenantProvisioningHook::class, new class implements TenantProvisioningHook
        {
            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($checkpoint === TenantProvisioningCheckpoint::AfterActivationBeforeSuccessAudit) {
                    throw new RuntimeException('Simulated activation transaction failure.');
                }
            }
        });

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('The activation hook should roll back the activation transaction.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('SHOP_ACTIVATION_FAILED', $exception->errorCode);
        }

        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $this->assertSame(ShopStatus::Failed, $shop->status);
        $this->assertNull($shop->provisioned_at);
        $this->assertDatabaseMissing('shop_health_snapshots', [
            'shop_id' => $shop->getKey(),
        ], 'central');
        $this->assertDatabaseMissing('shop_lifecycle_activities', [
            'shop_id' => $shop->getKey(),
            'event' => ShopLifecycleEvent::ProvisioningSucceeded->value,
        ], 'central');

        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);
        $shop = app(ProvisionShop::class)->retry($shop);
        $this->assertSame(ShopStatus::Active, $shop->status);
    }

    #[DataProvider('handledTenantFailureCheckpoints')]
    public function test_tenant_failure_stages_are_safe_retryable_and_preserve_the_database(
        TenantProvisioningCheckpoint $failurePoint,
        string $expectedCode,
    ): void {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'handled-failure.sqlite';
        $this->app->instance(TenantProvisioningHook::class, new class($failurePoint) implements TenantProvisioningHook
        {
            public function __construct(
                private readonly TenantProvisioningCheckpoint $failurePoint,
            ) {}

            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($checkpoint === $this->failurePoint) {
                    throw new RuntimeException('Unsafe driver detail that must be discarded.');
                }
            }
        });

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('The selected tenant stage should fail.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
            $this->assertStringNotContainsString('Unsafe driver detail', $exception->getMessage());
        }

        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $this->assertSame(ShopStatus::Failed, $shop->status);
        $this->assertFileExists($database);
        $failureActivity = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::ProvisioningFailed->value)
            ->latest('occurred_at')
            ->firstOrFail();
        $this->assertSame($expectedCode, $failureActivity->metadata['error_code']);

        if (in_array($failurePoint, [
            TenantProvisioningCheckpoint::BeforeOwnerProvision,
            TenantProvisioningCheckpoint::BeforeAuthorizationSync,
        ], true)) {
            app(TenantConnectionManager::class)->within($shop, function (): void {
                $this->assertSame(0, User::query()->where('username', 'test-owner')->count());
            });
        }

        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);
        $shop = app(ProvisionShop::class)->retry($shop, 'temporary-owner-password');
        $this->assertSame(ShopStatus::Active, $shop->status);
    }

    /** @return array<string, array{TenantProvisioningCheckpoint, string}> */
    public static function handledTenantFailureCheckpoints(): array
    {
        return [
            'owner' => [TenantProvisioningCheckpoint::BeforeOwnerProvision, 'OWNER_PROVISION_FAILED'],
            'authorization' => [
                TenantProvisioningCheckpoint::BeforeAuthorizationSync,
                'AUTHORIZATION_SYNC_FAILED',
            ],
            'seed' => [TenantProvisioningCheckpoint::BeforeReferenceSeed, 'TENANT_SEED_FAILED'],
        ];
    }

    public function test_shared_central_lock_rejects_concurrent_work_without_state_change(): void
    {
        config()->set('cache.default', 'array');
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'locked.sqlite';
        $shop = Shop::registerForProvisioning(
            name: 'Locked Workshop',
            slug: 'locked-workshop',
            databaseDriver: 'sqlite',
            databaseName: $database,
        );
        $lock = Cache::store('database')->lock('tenant-provision:'.$shop->getKey(), 60);
        $this->assertTrue($lock->get());

        try {
            try {
                app(ProvisionShop::class)->retry($shop, 'temporary-owner-password');
                $this->fail('The second worker must not enter provisioning.');
            } catch (TenantProvisioningException $exception) {
                $this->assertSame('PROVISIONING_BUSY', $exception->errorCode);
            }

            $this->assertSame(ShopStatus::Provisioning, $shop->fresh()->status);
            $this->assertSame(0, $shop->lifecycleActivities()->count());
            $this->assertFileDoesNotExist($database);
        } finally {
            $lock->release();
        }
    }

    public function test_shared_central_lock_coordinates_a_separate_worker_process(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'cross-process-locked.sqlite';
        $readyFile = $this->databaseRoot.DIRECTORY_SEPARATOR.'lock-ready';
        $shop = Shop::registerForProvisioning(
            name: 'Cross Process Locked Workshop',
            slug: 'cross-process-locked-workshop',
            databaseDriver: 'sqlite',
            databaseName: $database,
        );
        $script = <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $central = config('database.connections.central');
            $central['driver'] = 'sqlite';
            $central['database'] = getenv('LOCK_TEST_CENTRAL');
            unset($central['url'], $central['host'], $central['port'], $central['username'], $central['password'], $central['unix_socket']);
            config()->set('database.connections.central', $central);
            config()->set('cache.stores.database.connection', 'central');
            config()->set('cache.stores.database.lock_connection', 'central');
            Illuminate\Support\Facades\DB::purge('central');
            $lock = Illuminate\Support\Facades\Cache::store('database')->lock(getenv('LOCK_TEST_KEY'), 10);
            if (! $lock->get()) { exit(2); }
            file_put_contents(getenv('LOCK_TEST_READY'), 'ready');
            usleep(3000000);
            $lock->release();
            PHP;
        $process = new Process(
            [PHP_BINARY, '-r', $script],
            base_path(),
            [
                'LOCK_TEST_CENTRAL' => $this->centralDatabase,
                'LOCK_TEST_KEY' => 'tenant-provision:'.$shop->getKey(),
                'LOCK_TEST_READY' => $readyFile,
            ],
        );
        $process->setTimeout(10);
        $process->start();
        $deadline = microtime(true) + 5;

        while (! is_file($readyFile) && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }

        try {
            $this->assertFileExists($readyFile, $process->getErrorOutput());

            try {
                app(ProvisionShop::class)->retry($shop, 'temporary-owner-password');
                $this->fail('A second process must not enter provisioning.');
            } catch (TenantProvisioningException $exception) {
                $this->assertSame('PROVISIONING_BUSY', $exception->errorCode);
            }

            $this->assertSame(ShopStatus::Provisioning, $shop->fresh()->status);
            $this->assertFileDoesNotExist($database);
        } finally {
            $process->wait();
        }

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_renewed_lease_keeps_a_second_worker_busy_after_the_original_expiration(): void
    {
        config()->set('database.tenant_provisioning_lock_seconds', 300);
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'renewed-lease.sqlite';
        $this->travelTo('2026-09-02 12:00:00');
        $hook = new class(fn () => $this->travel(250)->seconds()) implements TenantProvisioningHook
        {
            public ?string $secondWorkerErrorCode = null;

            public bool $overlapDetected = false;

            private int $elapsedCheckpoints = 0;

            private bool $secondWorkerRunning = false;

            public function __construct(private readonly \Closure $advanceTime) {}

            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($this->secondWorkerRunning) {
                    $this->overlapDetected = true;

                    return;
                }

                if (! in_array($checkpoint, [
                    TenantProvisioningCheckpoint::AfterPhysicalCreateBeforeAuthorization,
                    TenantProvisioningCheckpoint::AfterAuthorization,
                    TenantProvisioningCheckpoint::AfterMarkerTableDdlBeforeLog,
                    TenantProvisioningCheckpoint::AfterMarkerMigrationLoggedBeforeRow,
                ], true)) {
                    return;
                }

                ($this->advanceTime)();
                $this->elapsedCheckpoints++;

                if ($this->elapsedCheckpoints !== 4) {
                    return;
                }

                $this->secondWorkerRunning = true;

                try {
                    app(ProvisionShop::class)->retry($shop->fresh(), 'second-worker-password');
                    $this->secondWorkerErrorCode = 'COMPLETED';
                } catch (TenantProvisioningException $exception) {
                    $this->secondWorkerErrorCode = $exception->errorCode;
                } finally {
                    $this->secondWorkerRunning = false;
                }
            }
        };
        $this->app->instance(TenantProvisioningHook::class, $hook);

        try {
            $shop = app(ProvisionShop::class)->handle($this->provisioningData($database));
        } finally {
            $this->travelBack();
        }

        $this->assertSame('PROVISIONING_BUSY', $hook->secondWorkerErrorCode);
        $this->assertFalse($hook->overlapDetected);
        $this->assertSame(ShopStatus::Active, $shop->status);
        $this->assertSame(1, $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::ProvisioningStarted->value)
            ->count());
        $this->assertSame(1, $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::TenantInstallationAuthorized->value)
            ->count());
        $this->assertSame(1, $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::ProvisioningSucceeded->value)
            ->count());

        app(TenantConnectionManager::class)->within($shop, function (): void {
            $this->assertSame(1, User::query()->where('username', 'test-owner')->count());
            $this->assertSame(
                DB::connection('tenant')->table('migrations')->count(),
                DB::connection('tenant')->table('migrations')->distinct()->count('migration'),
            );
        });
    }

    public function test_lost_lease_stops_before_receipt_tenant_failure_or_activation_writes(): void
    {
        config()->set('database.tenant_provisioning_lock_seconds', 300);
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'lost-lease.sqlite';
        $this->travelTo('2026-09-02 12:00:00');
        $hook = new class(fn () => $this->travel(901)->seconds()) implements TenantProvisioningHook
        {
            public ?Lock $replacementLock = null;

            public function __construct(private readonly \Closure $expireLease) {}

            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($checkpoint !== TenantProvisioningCheckpoint::AfterPhysicalCreateBeforeAuthorization) {
                    return;
                }

                ($this->expireLease)();
                $this->replacementLock = Cache::store('database')->lock(
                    'tenant-provision:'.$shop->getKey(),
                    300,
                );

                if (! $this->replacementLock->get()) {
                    throw new RuntimeException('The replacement worker could not acquire the expired lease.');
                }
            }
        };
        $this->app->instance(TenantProvisioningHook::class, $hook);
        $exception = null;

        try {
            try {
                app(ProvisionShop::class)->handle($this->provisioningData($database));
            } catch (TenantProvisioningException $caught) {
                $exception = $caught;
            }
        } finally {
            $hook->replacementLock?->release();
            $this->travelBack();
        }

        $this->assertInstanceOf(TenantProvisioningException::class, $exception);
        $this->assertSame('PROVISIONING_LOCK_LOST', $exception->errorCode);
        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $this->assertSame(ShopStatus::Provisioning, $shop->status);
        $this->assertNull($shop->provisioning_failure_message);
        $this->assertFileExists($database);
        clearstatcache(true, $database);
        $this->assertSame(0, filesize($database));
        $this->assertSame(
            [ShopLifecycleEvent::ProvisioningStarted],
            $shop->lifecycleActivities()->oldest('occurred_at')->pluck('event')->all(),
        );
        $this->assertDatabaseMissing('shop_health_snapshots', [
            'shop_id' => $shop->getKey(),
        ], 'central');
    }

    public function test_stale_retry_never_touches_a_target_changed_before_lock_acquisition(): void
    {
        $oldDatabase = $this->tenantRoot.DIRECTORY_SEPARATOR.'stale-old.sqlite';
        $newDatabase = $this->tenantRoot.DIRECTORY_SEPARATOR.'stale-new.sqlite';
        $shop = Shop::registerForProvisioning(
            name: 'Stale Retry Workshop',
            slug: 'stale-retry-workshop',
            databaseDriver: 'sqlite',
            databaseName: $oldDatabase,
        );
        $shop->markProvisioningFailed('A safe test failure.');
        $staleShop = $shop->fresh();
        $shop->fresh()->updateProvisioningTarget(
            slug: 'stale-retry-workshop',
            databaseDriver: 'sqlite',
            databaseName: $newDatabase,
        );

        try {
            app(ProvisionShop::class)->retry($staleShop, 'temporary-owner-password');
            $this->fail('A stale retry must not follow a target changed while it waited.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('TARGET_IDENTITY_CHANGED', $exception->errorCode);
        }

        $this->assertSame(ShopStatus::Failed, $shop->fresh()->status);
        $this->assertFileDoesNotExist($oldDatabase);
        $this->assertFileDoesNotExist($newDatabase);
        $this->assertSame(0, $shop->lifecycleActivities()->count());
    }

    public function test_status_change_before_sqlite_materialization_prevents_receipt_publication(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'status-race.sqlite';
        $this->app->instance(TenantProvisioningHook::class, new class implements TenantProvisioningHook
        {
            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($checkpoint === TenantProvisioningCheckpoint::AfterPhysicalCreateBeforeAuthorization) {
                    $shop->markProvisioningFailed('A concurrent operator stopped provisioning.');
                }
            }
        });

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('The central state change must stop identity publication.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('TARGET_IDENTITY_CHANGED', $exception->errorCode);
        }

        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $this->assertSame(ShopStatus::Failed, $shop->status);
        $this->assertSame(
            $shop->database_target_locator_fingerprint,
            $shop->database_target_fingerprint,
        );
        $this->assertFileExists($database);
        $this->assertDatabaseMissing('shop_lifecycle_activities', [
            'shop_id' => $shop->getKey(),
            'event' => ShopLifecycleEvent::TenantInstallationAuthorized->value,
        ], 'central');
    }

    public function test_failure_surfaces_and_central_rows_never_expose_credentials_or_topology(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'private-target.sqlite';
        $ownerPassword = 'ultra-private-owner-password';
        $databasePassword = 'private-database-password';
        $databaseUsername = 'private-database-user';
        $driverDetail = 'SQLSTATE mysql:host=db.internal;dbname=private_schema CREATE TABLE at '
            .$database.' using '.$ownerPassword.' '.$databaseUsername.' '.$databasePassword;
        Log::spy();
        $this->app->instance(DatabaseProvisioner::class, new class($driverDetail) implements DatabaseProvisioner
        {
            public function __construct(private readonly string $driverDetail) {}

            public function provision(
                #[\SensitiveParameter]
                Shop $shop,
                TenantProvisioningLease $lease,
            ): void {
                throw new RuntimeException($this->driverDetail);
            }
        });
        $data = new ProvisionShopData(
            name: 'Private Workshop',
            slug: 'private-workshop',
            databaseDriver: 'sqlite',
            databaseName: $database,
            ownerName: 'Private Owner',
            ownerUsername: 'private-owner',
            ownerEmail: null,
            temporaryOwnerPassword: $ownerPassword,
            databaseUsername: $databaseUsername,
            databasePassword: $databasePassword,
        );

        try {
            app(ProvisionShop::class)->handle($data);
            $this->fail('The injected driver failure should be sanitized.');
        } catch (TenantProvisioningException $exception) {
            $shop = Shop::query()->where('slug', 'private-workshop')->firstOrFail();
            $rawShop = DB::connection('central')->table('shops')->where('id', $shop->getKey())->first();
            $failureActivity = $shop->lifecycleActivities()
                ->where('event', ShopLifecycleEvent::ProvisioningFailed->value)
                ->firstOrFail();
            $surface = implode('\n', [
                $exception->getMessage(),
                print_r($exception->getTrace(), true),
                (string) $shop->provisioning_failure_message,
                json_encode($rawShop, JSON_THROW_ON_ERROR),
                json_encode($failureActivity->metadata, JSON_THROW_ON_ERROR),
                print_r($data, true),
            ]);
            $attestationHmac = $shop->databaseAttestationHmac();
            $attestationKey = (string) $shop->database_attestation_key;
            $shopId = (string) $shop->getKey();

            $this->assertSame('DATABASE_PROVISION_FAILED', $exception->errorCode);
            $this->assertStringNotContainsString($ownerPassword, $surface);
            $this->assertStringNotContainsString($databaseUsername, $surface);
            $this->assertStringNotContainsString($databasePassword, $surface);
            $this->assertStringNotContainsString($database, $surface);
            $this->assertStringNotContainsString($driverDetail, $surface);
            $this->assertStringNotContainsString('SQLSTATE', $surface);
            $this->assertStringNotContainsString('db.internal', $surface);
            $this->assertStringNotContainsString('private_schema', $surface);
            $this->assertStringNotContainsString('CREATE TABLE', $surface);
            $this->assertStringNotContainsString($attestationHmac, $surface);
            $this->assertStringNotContainsString($attestationKey, $surface);
            $this->assertSame([
                'attempt' => 1,
                'failure_stage' => 'database',
                'error_code' => 'DATABASE_PROVISION_FAILED',
            ], $failureActivity->metadata);
            Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) use (
                $ownerPassword,
                $databaseUsername,
                $databasePassword,
                $database,
                $attestationHmac,
                $attestationKey,
                $shopId,
            ): bool {
                $logged = $message.json_encode($context, JSON_THROW_ON_ERROR);

                foreach ([
                    $ownerPassword,
                    $databaseUsername,
                    $databasePassword,
                    $database,
                    $attestationHmac,
                    $attestationKey,
                    'SQLSTATE',
                    'db.internal',
                    'private_schema',
                    'CREATE TABLE',
                ] as $sensitiveValue) {
                    $this->assertStringNotContainsString($sensitiveValue, $logged);
                }

                return ($context['shop_id'] ?? null) === $shopId;
            })->once();
        }
    }

    public function test_command_resumes_with_a_protected_secret_file_without_echoing_it(): void
    {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.'command-resume.sqlite';
        $passwordFile = $this->databaseRoot.DIRECTORY_SEPARATOR.'owner-secret.txt';
        $password = 'command-temporary-password';
        $this->interruptAt(TenantProvisioningCheckpoint::AfterTenantMigrations);

        try {
            app(ProvisionShop::class)->handle($this->provisioningData($database));
            $this->fail('The hook should stop before the command retry.');
        } catch (TenantProvisioningInterrupted) {
        }

        $written = file_put_contents($passwordFile, $password.PHP_EOL);
        $this->assertIsInt($written);

        if (DIRECTORY_SEPARATOR === '/') {
            chmod($passwordFile, 0600);
        }

        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);
        $this->artisan('tenants:provision', [
            'shop' => 'test-workshop',
            '--owner-password-file' => $passwordFile,
            '--force' => true,
        ])
            ->expectsOutputToContain('Shop provisioning completed.')
            ->doesntExpectOutputToContain($password)
            ->doesntExpectOutputToContain($passwordFile)
            ->assertSuccessful();

        $shop = Shop::query()->where('slug', 'test-workshop')->firstOrFail();
        $this->assertSame(ShopStatus::Active, $shop->status);
        app(TenantConnectionManager::class)->within($shop, function () use ($password): void {
            $owner = User::query()->where('username', 'test-owner')->firstOrFail();
            $this->assertTrue(Hash::check($password, $owner->password));
        });
    }

    private function interruptAt(TenantProvisioningCheckpoint $interruptionPoint): void
    {
        $this->app->instance(TenantProvisioningHook::class, new class($interruptionPoint) implements TenantProvisioningHook
        {
            public function __construct(
                private readonly TenantProvisioningCheckpoint $interruptionPoint,
            ) {}

            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($checkpoint === $this->interruptionPoint) {
                    throw new TenantProvisioningInterrupted;
                }
            }
        });
    }

    /** @return array<string, int|string> */
    private function tenantProvisioningSnapshot(Shop $shop): array
    {
        return app(TenantConnectionManager::class)->within($shop, static function (): array {
            $owner = User::query()->where('username', 'test-owner')->firstOrFail();

            return [
                'owner_id' => $owner->id,
                'owner_password' => $owner->password,
                'users' => User::query()->count(),
                'roles' => Role::query()->count(),
                'permissions' => Permission::query()->count(),
                'modules' => ModuleSetting::query()->count(),
                'items' => Item::query()->count(),
                'makes' => VehicleMake::query()->count(),
                'models' => VehicleModel::query()->count(),
                'migrations' => DB::connection('tenant')->table('migrations')->count(),
            ];
        });
    }

    private function provisioningData(string $database): ProvisionShopData
    {
        return new ProvisionShopData(
            name: 'Test Workshop',
            slug: 'test-workshop',
            databaseDriver: 'sqlite',
            databaseName: $database,
            ownerName: 'Test Owner',
            ownerUsername: 'test-owner',
            ownerEmail: 'owner@example.test',
            temporaryOwnerPassword: 'temporary-owner-password',
        );
    }

    private function createEmptyDatabase(string $database): void
    {
        $handle = @fopen($database, 'x+b');

        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to create the test database.');
        }

        fclose($handle);
    }
}
