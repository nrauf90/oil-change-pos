<?php

namespace Tests\Feature\Console;

use App\Actions\Tenancy\ProvisionShop;
use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Exceptions\TenantProvisioningException;
use App\Models\Central\Shop;
use App\Models\User;
use App\Tenancy\NormalizedDatabaseTarget;
use App\Tenancy\Provisioning\NullTenantProvisioningHook;
use App\Tenancy\Provisioning\TenantProvisioningCheckpoint;
use App\Tenancy\Provisioning\TenantProvisioningHook;
use App\Tenancy\Provisioning\TenantProvisioningInterrupted;
use App\Tenancy\TenantConnectionAttestationHook;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class AdoptExistingTenantTest extends TestCase
{
    private const MARKER_MIGRATION = '2026_09_02_042731_create_tenant_installations_table';

    private const ROLE_DESCRIPTION_MIGRATION = '2026_09_02_121450_add_description_to_roles_table';

    private const LEGACY_TENANT_MIGRATION_CUTOFF = '2026_09_01_135125_create_item_vehicle_compatibilities_table';

    private const LEGACY_NON_TENANT_MIGRATIONS = [
        '0001_01_01_000001_create_cache_table',
        '0001_01_01_000002_create_jobs_table',
    ];

    private string $databaseRoot;

    private string $legacyDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $this->databaseRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tenant-adoption-'.Str::uuid();
        (new Filesystem)->ensureDirectoryExists($this->databaseRoot, 0700);
        $centralDatabase = $this->databaseRoot.DIRECTORY_SEPARATOR.'central.sqlite';
        $this->legacyDatabase = $this->databaseRoot.DIRECTORY_SEPARATOR.'legacy.sqlite';
        $this->createEmptyDatabase($centralDatabase);
        $this->createEmptyDatabase($this->legacyDatabase);

        config()->set('database.tenant_sqlite_root', $this->databaseRoot);
        config()->set(
            'database.tenant_attestation_lock_path',
            $this->databaseRoot.DIRECTORY_SEPARATOR.'attestation-locks',
        );
        config()->set('cache.default', 'database');
        config()->set('cache.stores.database.connection', 'central');
        config()->set('cache.stores.database.lock_connection', 'central');
        $this->configureCentralDatabase($centralDatabase);
        config()->set(
            'database.connections.legacy',
            $this->sqliteTestConnectionConfiguration($this->legacyDatabase),
        );
        config()->set('database.default', 'legacy');
        DB::purge('central');
        DB::purge('legacy');
        DB::purge('tenant');
        $this->migrateCentralDatabase();
        $this->createLegacySchema();
        $this->createLegacyOwner();
    }

    protected function tearDown(): void
    {
        try {
            app(TenantConnectionManager::class)->disconnect();
            DB::purge('legacy');
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

    public function test_adoption_is_a_byte_for_byte_read_only_dry_run_by_default(): void
    {
        $writeProbe = new class implements TenantConnectionAttestationHook
        {
            public bool $writeWasRejected = false;

            public function beforeOpen(NormalizedDatabaseTarget $target): void {}

            public function afterOpen(PDO $pdo, NormalizedDatabaseTarget $target): void
            {
                if ($target->driver !== 'sqlite') {
                    return;
                }

                try {
                    $pdo->exec('CREATE TABLE dry_run_write_probe (id INTEGER PRIMARY KEY)');
                } catch (PDOException) {
                    $this->writeWasRejected = true;
                }
            }

            public function afterSqliteNonceWritten(PDO $pdo, NormalizedDatabaseTarget $target): void {}
        };
        $this->app->instance(TenantConnectionAttestationHook::class, $writeProbe);
        DB::purge('legacy');
        $beforeHash = hash_file('sha256', $this->legacyDatabase);
        $beforeTables = $this->sourceTableNames();

        $exitCode = Artisan::call('tenants:adopt-existing', $this->adoptionOptions());
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Dry run passed', $output);
        $this->assertStringContainsString('owner=legacy-admin', $output);
        $this->assertStringNotContainsString($this->legacyDatabase, $output);

        DB::purge('legacy');
        clearstatcache(true, $this->legacyDatabase);
        $this->assertTrue($writeProbe->writeWasRejected);
        $this->assertSame($beforeHash, hash_file('sha256', $this->legacyDatabase));
        $this->assertSame($beforeTables, $this->sourceTableNames());
        $this->assertFileDoesNotExist($this->legacyDatabase.'-journal');
        $this->assertFileDoesNotExist($this->legacyDatabase.'-wal');
        $this->assertFileDoesNotExist($this->legacyDatabase.'-shm');
        $this->assertSame(0, Shop::query()->count());
        $this->assertDatabaseCount('shop_owners', 0, 'central');
        $this->assertDatabaseCount('shop_lifecycle_activities', 0, 'central');
        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    public function test_adoption_rejects_a_missing_required_table_without_writes(): void
    {
        DB::connection('legacy')->getSchemaBuilder()->drop('sale_items');
        DB::purge('legacy');

        $this->artisan('tenants:adopt-existing', $this->adoptionOptions())
            ->expectsOutputToContain('ADOPTION_REQUIRED_TABLES_MISSING')
            ->assertExitCode(1);

        $this->assertSame(0, Shop::query()->count());
        $this->assertDatabaseCount('shop_lifecycle_activities', 0, 'central');
        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    public function test_adoption_rejects_non_marker_pending_migrations_without_writes(): void
    {
        DB::connection('legacy')->table('migrations')
            ->where('migration', '2026_09_01_135124_add_is_universal_to_items_table')
            ->delete();
        DB::purge('legacy');

        $this->artisan('tenants:adopt-existing', $this->adoptionOptions())
            ->expectsOutputToContain('ADOPTION_MIGRATIONS_PENDING')
            ->assertExitCode(1);

        $this->assertSame(0, Shop::query()->count());
        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    public function test_adoption_rejects_non_legacy_central_migration_history_without_writes(): void
    {
        DB::connection('legacy')->table('migrations')->insert([
            'migration' => '2026_09_01_000001_create_platform_users_table',
            'batch' => 1,
        ]);
        DB::purge('legacy');

        $this->artisan('tenants:adopt-existing', $this->adoptionOptions())
            ->expectsOutputToContain('ADOPTION_UNKNOWN_MIGRATIONS')
            ->assertExitCode(1);

        $this->assertSame(0, Shop::query()->count());
        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    public function test_force_registers_the_shop_owner_health_receipt_and_adoption_without_changing_operational_data(): void
    {
        $source = DB::connection('legacy');
        $source->table('items')->insert([
            'name' => 'Preserved filter',
            'type' => 'product',
        ]);
        $beforeTables = $this->sourceTableNames();
        $beforeColumns = $source->getSchemaBuilder()->getColumnListing('items');
        $beforeItem = (array) $source->table('items')->where('name', 'Preserved filter')->first();
        $beforeUser = (array) $source->table('users')->where('username', 'legacy-admin')->first();
        $beforeMigrations = $source->table('migrations')->orderBy('migration')->pluck('migration')->all();
        DB::purge('legacy');

        $exitCode = Artisan::call('tenants:adopt-existing', [
            ...$this->adoptionOptions(),
            '--force' => true,
            '--no-interaction' => true,
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Existing database adopted', $output);
        $this->assertStringNotContainsString($this->legacyDatabase, $output);

        $shop = Shop::query()->where('slug', 'legacy-workshop')->firstOrFail();
        $this->assertSame(ShopStatus::Active, $shop->status);
        $this->assertDatabaseHas('shop_owners', [
            'shop_id' => $shop->getKey(),
            'name' => 'Legacy Owner',
            'username' => 'legacy-admin',
            'email' => 'owner@example.test',
            'is_active' => true,
        ], 'central');
        $this->assertDatabaseHas('shop_health_snapshots', [
            'shop_id' => $shop->getKey(),
            'migration_status' => 'current',
        ], 'central');
        $this->assertDatabaseHas('shop_lifecycle_activities', [
            'shop_id' => $shop->getKey(),
            'event' => ShopLifecycleEvent::TenantInstallationAuthorized->value,
        ], 'central');
        $this->assertDatabaseHas('shop_lifecycle_activities', [
            'shop_id' => $shop->getKey(),
            'event' => ShopLifecycleEvent::ExistingDatabaseAdopted->value,
        ], 'central');

        $source = DB::connection('legacy');
        $afterTables = $this->sourceTableNames();
        $this->assertEqualsCanonicalizing(
            [...$beforeTables, 'tenant_installations'],
            $afterTables,
        );
        $this->assertSame($beforeColumns, $source->getSchemaBuilder()->getColumnListing('items'));
        $this->assertSame($beforeItem, (array) $source->table('items')->where('name', 'Preserved filter')->first());
        $this->assertSame($beforeUser, (array) $source->table('users')->where('username', 'legacy-admin')->first());
        $expectedMigrations = [
            ...$beforeMigrations,
            self::MARKER_MIGRATION,
            self::ROLE_DESCRIPTION_MIGRATION,
        ];
        sort($expectedMigrations);

        $this->assertSame(
            $expectedMigrations,
            $source->table('migrations')->orderBy('migration')->pluck('migration')->all(),
        );
        $this->assertTrue($source->getSchemaBuilder()->hasColumn('roles', 'description'));
        $this->assertSame(1, $source->table('tenant_installations')->count());
        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    public function test_adoption_resumes_after_marker_installation_before_pending_migration(): void
    {
        $this->app->instance(TenantProvisioningHook::class, new class implements TenantProvisioningHook
        {
            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($checkpoint === TenantProvisioningCheckpoint::BeforeTenantMigrationRun) {
                    throw new TenantProvisioningInterrupted;
                }
            }
        });
        $options = [
            ...$this->adoptionOptions(),
            '--force' => true,
            '--no-interaction' => true,
        ];

        $this->artisan('tenants:adopt-existing', $options)
            ->expectsOutputToContain('TENANT_MIGRATION_FAILED')
            ->assertExitCode(1);

        DB::purge('legacy');
        $source = DB::connection('legacy');
        $this->assertSame(
            1,
            $source->table('migrations')->where('migration', self::MARKER_MIGRATION)->count(),
        );
        $this->assertFalse($source->getSchemaBuilder()->hasColumn('roles', 'description'));
        $this->assertSame(
            ShopStatus::Provisioning,
            Shop::query()->where('slug', 'legacy-workshop')->firstOrFail()->status,
        );

        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);

        $this->artisan('tenants:adopt-existing', $options)
            ->expectsOutputToContain('Existing database adopted')
            ->assertSuccessful();

        DB::purge('legacy');
        $source = DB::connection('legacy');
        $this->assertTrue($source->getSchemaBuilder()->hasColumn('roles', 'description'));
        $this->assertSame(
            1,
            $source->table('migrations')->where('migration', self::MARKER_MIGRATION)->count(),
        );
        $this->assertSame(
            1,
            $source->table('migrations')->where('migration', self::ROLE_DESCRIPTION_MIGRATION)->count(),
        );
        $this->assertSame(
            $source->table('migrations')->count(),
            $source->table('migrations')->distinct()->count('migration'),
        );
        $this->assertSame(
            ShopStatus::Active,
            Shop::query()->where('slug', 'legacy-workshop')->firstOrFail()->status,
        );
    }

    public function test_adoption_recovers_after_role_description_ddl_before_migration_log(): void
    {
        $this->app->instance(TenantProvisioningHook::class, new class implements TenantProvisioningHook
        {
            private bool $interrupted = false;

            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($checkpoint === TenantProvisioningCheckpoint::AfterTenantMigrationDdlBeforeLog
                    && ! $this->interrupted) {
                    $this->interrupted = true;

                    throw new TenantProvisioningInterrupted;
                }
            }
        });
        $options = [
            ...$this->adoptionOptions(),
            '--force' => true,
            '--no-interaction' => true,
        ];

        $this->artisan('tenants:adopt-existing', $options)
            ->expectsOutputToContain('TENANT_MIGRATION_FAILED')
            ->assertExitCode(1);

        DB::purge('legacy');
        $source = DB::connection('legacy');
        $this->assertTrue($source->getSchemaBuilder()->hasColumn('roles', 'description'));
        $this->assertSame(
            0,
            $source->table('migrations')->where('migration', self::ROLE_DESCRIPTION_MIGRATION)->count(),
        );

        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);

        $this->artisan('tenants:adopt-existing', $options)
            ->expectsOutputToContain('Existing database adopted')
            ->assertSuccessful();

        DB::purge('legacy');
        $source = DB::connection('legacy');
        $description = collect($source->getSchemaBuilder()->getColumns('roles'))
            ->firstWhere('name', 'description');
        $this->assertIsArray($description);
        $this->assertSame('text', $description['type_name']);
        $this->assertTrue($description['nullable']);
        $this->assertSame(
            1,
            $source->table('migrations')->where('migration', self::ROLE_DESCRIPTION_MIGRATION)->count(),
        );
        $this->assertSame(
            ShopStatus::Active,
            Shop::query()->where('slug', 'legacy-workshop')->firstOrFail()->status,
        );
    }

    public function test_normal_provisioning_retry_cannot_take_over_an_interrupted_forced_adoption(): void
    {
        $this->app->instance(TenantProvisioningHook::class, new class implements TenantProvisioningHook
        {
            public function reached(TenantProvisioningCheckpoint $checkpoint, Shop $shop): void
            {
                if ($checkpoint === TenantProvisioningCheckpoint::BeforeTenantMigrationRun) {
                    throw new TenantProvisioningInterrupted;
                }
            }
        });
        $options = [
            ...$this->adoptionOptions(),
            '--force' => true,
            '--no-interaction' => true,
        ];

        $this->artisan('tenants:adopt-existing', $options)->assertExitCode(1);
        $shop = Shop::query()->where('slug', 'legacy-workshop')->firstOrFail();
        $beforeItems = DB::connection('legacy')->table('items')->count();
        $this->app->instance(TenantProvisioningHook::class, new NullTenantProvisioningHook);

        try {
            app(ProvisionShop::class)->retry($shop, 'temporary-owner-password');
            $this->fail('Normal provisioning must not take over a forced adoption.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('SHOP_RESERVED_FOR_ADOPTION', $exception->errorCode);
        }

        $this->assertSame(ShopStatus::Provisioning, $shop->fresh()->status);
        $this->assertSame($beforeItems, DB::connection('legacy')->table('items')->count());
        $this->assertSame(0, $shop->lifecycleActivities()
            ->whereIn('event', [
                ShopLifecycleEvent::ProvisioningStarted->value,
                ShopLifecycleEvent::ProvisioningFailed->value,
                ShopLifecycleEvent::ProvisioningSucceeded->value,
            ])
            ->count());

        $this->artisan('tenants:adopt-existing', $options)->assertSuccessful();
        $this->assertSame(ShopStatus::Active, $shop->fresh()->status);
        $this->assertSame(1, $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::ExistingDatabaseAdopted->value)
            ->count());
    }

    public function test_adoption_rejects_wal_mode_sqlite_without_touching_database_files(): void
    {
        DB::connection('legacy')->select('PRAGMA journal_mode = WAL');
        DB::purge('legacy');
        $beforeFiles = $this->sqliteFileHashes();

        $this->artisan('tenants:adopt-existing', $this->adoptionOptions())
            ->expectsOutputToContain('ADOPTION_SQLITE_WAL_UNSAFE')
            ->assertExitCode(1);

        clearstatcache();
        $this->assertSame($beforeFiles, $this->sqliteFileHashes());
        $this->assertSame(0, Shop::query()->count());
    }

    public function test_successfully_adopted_database_cannot_be_adopted_twice(): void
    {
        $options = [
            ...$this->adoptionOptions(),
            '--force' => true,
            '--no-interaction' => true,
        ];

        $this->artisan('tenants:adopt-existing', $options)->assertSuccessful();
        $activityCount = DB::connection('central')->table('shop_lifecycle_activities')->count();

        $this->artisan('tenants:adopt-existing', $options)
            ->expectsOutputToContain('ADOPTION_ALREADY_COMPLETED')
            ->assertExitCode(1);

        $this->assertSame(1, Shop::query()->count());
        $this->assertSame(
            $activityCount,
            DB::connection('central')->table('shop_lifecycle_activities')->count(),
        );
        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    public function test_adoption_slug_matches_the_resolver_sixty_three_character_boundary(): void
    {
        $validOptions = $this->adoptionOptions();
        $validOptions['--slug'] = str_repeat('a', 63);

        $this->artisan('tenants:adopt-existing', $validOptions)
            ->expectsOutputToContain('Dry run passed')
            ->assertSuccessful();

        $invalidOptions = $this->adoptionOptions();
        $invalidOptions['--slug'] = str_repeat('a', 64);

        $this->artisan('tenants:adopt-existing', $invalidOptions)
            ->expectsOutputToContain('ADOPTION_INPUT_INVALID')
            ->assertExitCode(1);

        $this->assertSame(0, Shop::query()->count());
    }

    /** @return array<string, string> */
    private function adoptionOptions(): array
    {
        return [
            '--name' => 'Legacy Workshop',
            '--slug' => 'legacy-workshop',
            '--owner-username' => 'legacy-admin',
            '--owner-name' => 'Legacy Owner',
            '--owner-email' => 'owner@example.test',
        ];
    }

    private function createLegacySchema(): void
    {
        $schema = Schema::connection('legacy');
        $schema->create('migrations', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('password');
            $table->boolean('is_active');
            $table->timestamps();
        });
        $schema->create('roles', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
        });
        $schema->create('permissions', static function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('model_has_roles', static function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
        });
        $schema->create('modules', static function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('items', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type');
        });
        $schema->create('sales', static function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('sale_items', static function (Blueprint $table): void {
            $table->id();
        });

        $migrationNames = collect((new Filesystem)->glob($this->tenantMigrationPath().DIRECTORY_SEPARATOR.'*.php'))
            ->map(static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME))
            ->filter(static fn (string $migration): bool => $migration <= self::LEGACY_TENANT_MIGRATION_CUTOFF)
            ->concat(self::LEGACY_NON_TENANT_MIGRATIONS)
            ->sort()
            ->values();

        foreach ($migrationNames as $migrationName) {
            DB::connection('legacy')->table('migrations')->insert([
                'migration' => $migrationName,
                'batch' => 1,
            ]);
        }
    }

    private function createLegacyOwner(): void
    {
        $connection = DB::connection('legacy');
        $roleId = $connection->table('roles')->insertGetId([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);
        $userId = $connection->table('users')->insertGetId([
            'name' => 'Legacy Owner',
            'username' => 'legacy-admin',
            'password' => 'existing-password-hash',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $connection->table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => User::class,
            'model_id' => $userId,
        ]);
    }

    /** @return list<string> */
    private function sourceTableNames(): array
    {
        DB::purge('legacy');
        $tables = DB::connection('legacy')
            ->getSchemaBuilder()
            ->getTableListing(schemaQualified: false);
        sort($tables, SORT_STRING);

        return $tables;
    }

    private function createEmptyDatabase(string $database): void
    {
        $handle = @fopen($database, 'x+b');

        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to create the test database.');
        }

        fclose($handle);
    }

    /** @return array<string, string|null> */
    private function sqliteFileHashes(): array
    {
        $files = [
            $this->legacyDatabase,
            $this->legacyDatabase.'-journal',
            $this->legacyDatabase.'-wal',
            $this->legacyDatabase.'-shm',
        ];

        return collect($files)
            ->mapWithKeys(static fn (string $path): array => [
                $path => is_file($path) ? hash_file('sha256', $path) : null,
            ])
            ->all();
    }
}
