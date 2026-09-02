<?php

namespace Tests\Feature\Console;

use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Models\Central\Shop;
use App\Models\User;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AdoptExistingTenantTest extends TestCase
{
    private const MARKER_MIGRATION = '2026_09_02_042731_create_tenant_installations_table';

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
        $this->assertSame($beforeHash, hash_file('sha256', $this->legacyDatabase));
        $this->assertSame($beforeTables, $this->sourceTableNames());
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

        $this->artisan('tenants:adopt-existing', [
            ...$this->adoptionOptions(),
            '--force' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Existing database adopted')
            ->doesntExpectOutputToContain($this->legacyDatabase)
            ->assertSuccessful();

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
        $expectedMigrations = [...$beforeMigrations, self::MARKER_MIGRATION];
        sort($expectedMigrations);

        $this->assertSame(
            $expectedMigrations,
            $source->table('migrations')->orderBy('migration')->pluck('migration')->all(),
        );
        $this->assertSame(1, $source->table('tenant_installations')->count());
        $this->assertFalse(app(TenantContext::class)->initialized());
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
            ->reject(static fn (string $migration): bool => $migration === self::MARKER_MIGRATION)
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
}
