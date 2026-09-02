<?php

namespace Tests\Feature\Tenancy;

use App\Actions\RecordSale;
use App\Actions\RecordSupplierPayment;
use App\Enums\ItemType;
use App\Enums\SaleLineType;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\QuickItemController;
use App\Http\Requests\InspectionRequest;
use App\Http\Requests\ItemRequest;
use App\Http\Requests\QuickItemRequest;
use App\Models\ActivityLog;
use App\Models\Central\Shop;
use App\Models\Contracts\TenantScoped;
use App\Models\CustomerVehicle;
use App\Models\Expense;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\Item;
use App\Models\ItemVehicleCompatibility;
use App\Models\ModuleSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Supply;
use App\Models\User;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Modules\ModuleRegistry;
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\DatabaseTargetConfiguration;
use App\Tenancy\Exceptions\TenantDatabaseAttestationFailed;
use App\Tenancy\Exceptions\TenantNotInitialized;
use App\Tenancy\NormalizedDatabaseTarget;
use App\Tenancy\TenantConnectionAttestationHook;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantSqliteWitnessConnection;
use App\Tenancy\ValidatedTenantConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use ReflectionClass;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class TenantConnectionIsolationTest extends TestCase
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

        $this->tenantRoot = $this->newTemporaryDirectory('tenant-isolation-');
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
        $this->configureCentralDatabase($centralDatabase);
        DB::purge('tenant');

        $this->migrateCentralDatabase();

        $this->manager = app(TenantConnectionManager::class);
        $this->shopA = $this->createMigratedTenant('shop-a');
        $this->shopB = $this->createMigratedTenant('shop-b');
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

    public function test_operational_models_fail_closed_without_tenant_context(): void
    {
        $this->manager->disconnect();

        $this->expectException(TenantNotInitialized::class);

        Item::query()->count();
    }

    public function test_tenant_context_cannot_be_initialized_with_a_forged_shop(): void
    {
        $this->manager->disconnect();
        $context = app(TenantContext::class);

        $this->assertFalse(method_exists($context, 'initialize'));
        $this->assertFalse(method_exists($context, 'clear'));

        $forgedShop = new Shop;
        $forgedShop->setRawAttributes([
            'id' => (string) Str::uuid(),
            'name' => 'Forged shop',
            'slug' => 'forged-shop',
        ], true);
        $forgedShop->exists = true;

        try {
            $this->manager->connect($forgedShop);
            $this->fail('A synthetic persisted-looking Shop unlocked tenant models.');
        } catch (TenantDatabaseAttestationFailed) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($context->initialized());
        $this->expectException(TenantNotInitialized::class);

        Item::query()->count();
    }

    public function test_unsaved_shop_is_rejected_before_connection_validation_or_configuration(): void
    {
        $this->manager->disconnect();
        $descriptor = new class extends Shop
        {
            public int $validationCalls = 0;

            public function validatedDatabaseConnection(): ValidatedTenantConnection
            {
                $this->validationCalls++;

                throw new RuntimeException('Validation must not run for an unsaved Shop.');
            }
        };

        try {
            $this->manager->connect($descriptor);
            $this->fail('An unsaved Shop reached tenant connection validation.');
        } catch (LogicException $exception) {
            $this->assertSame('Tenant connections require a persisted shop.', $exception->getMessage());
        }

        $this->assertSame(0, $descriptor->validationCalls);
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_cold_tenant_connection_lookup_fails_before_manager_connects(): void
    {
        $this->manager->disconnect();

        try {
            DB::connection('tenant')->getPdo();
            $this->fail('A cold tenant connection was available without manager attestation.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('not configured', $exception->getMessage());
        }

        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    public function test_every_operational_model_uses_the_fail_closed_tenant_contract(): void
    {
        $operationalModels = [
            ActivityLog::class,
            CustomerVehicle::class,
            Expense::class,
            Inspection::class,
            InspectionItem::class,
            Item::class,
            ItemVehicleCompatibility::class,
            ModuleSetting::class,
            Sale::class,
            SaleItem::class,
            Supplier::class,
            SupplierPayment::class,
            Supply::class,
            VehicleMake::class,
            VehicleModel::class,
            User::class,
            Role::class,
            Permission::class,
        ];
        $this->manager->disconnect();

        foreach ($operationalModels as $operationalModel) {
            try {
                (new $operationalModel)->getConnectionName();
                $this->fail("{$operationalModel} resolved without tenant context.");
            } catch (TenantNotInitialized) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('central', (new Shop)->getConnectionName());
        $this->manager->connect($this->shopA);

        foreach ($operationalModels as $operationalModel) {
            $model = new $operationalModel;
            $this->assertSame('tenant', $model->getConnectionName());

            try {
                $model->setConnection('sqlite');
                $this->fail("{$operationalModel} bypassed the tenant connection.");
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Operational models cannot override the tenant connection.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_every_concrete_top_level_operational_model_declares_the_tenant_marker(): void
    {
        $modelFiles = glob(app_path('Models').DIRECTORY_SEPARATOR.'*.php');

        if (! is_array($modelFiles)) {
            $this->fail('Operational model discovery failed.');
        }

        foreach ($modelFiles as $modelFile) {
            $modelClass = 'App\\Models\\'.pathinfo($modelFile, PATHINFO_FILENAME);
            $reflection = new ReflectionClass($modelClass);

            if ($reflection->isAbstract()) {
                continue;
            }

            $this->assertTrue(
                $reflection->implementsInterface(TenantScoped::class),
                "{$modelClass} does not implement the tenant-scoped model contract.",
            );
        }
    }

    public function test_items_and_observer_activity_are_isolated_across_a_b_a_switches(): void
    {
        $this->manager->connect($this->shopA);
        $item = Item::factory()->create(['name' => 'Only in shop A']);

        $this->assertSame('tenant', $item->getConnectionName());
        $this->assertTrue(ActivityLog::query()->where('description', 'like', '%Only in shop A%')->exists());
        $this->manager->disconnect();

        $this->manager->connect($this->shopB);
        $this->assertFalse(Item::query()->where('name', 'Only in shop A')->exists());
        $this->assertFalse(ActivityLog::query()->where('description', 'like', '%Only in shop A%')->exists());
        $this->manager->disconnect();

        $this->manager->connect($this->shopA);
        $this->assertTrue(Item::query()->where('name', 'Only in shop A')->exists());
        $this->assertSame('central', config('database.default'));
    }

    public function test_wrong_tenant_installation_marker_is_rejected_before_context_initializes(): void
    {
        $this->replaceMarkerShopId($this->shopA, (string) Str::uuid());

        try {
            $this->manager->connect($this->shopA);
            $this->fail('A database with the wrong tenant marker was accepted.');
        } catch (TenantDatabaseAttestationFailed $exception) {
            $this->assertSame('Tenant database identity attestation failed.', $exception->getMessage());
        }

        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_mysql_dns_drift_is_rejected_before_pdo_open_while_the_tls_hostname_is_preserved(): void
    {
        $initialResolver = new class implements DatabaseHostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                $this->calls++;

                return ['192.0.2.10'];
            }
        };
        app()->instance(DatabaseHostResolver::class, $initialResolver);
        $shop = Shop::registerForProvisioning(
            name: 'DNS re-attestation',
            slug: 'dns-re-attestation',
            databaseDriver: 'mysql',
            databaseName: 'dns_re_attestation',
            databaseHost: 'tenant-db.example.test',
        );
        $snapshot = $shop->validatedDatabaseConnection();
        $driftedResolver = new class implements DatabaseHostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                $this->calls++;

                return ['192.0.2.11'];
            }
        };
        app()->instance(DatabaseHostResolver::class, $driftedResolver);

        $this->assertSame('tenant-db.example.test', $snapshot->connectionOverrides()['host']);

        try {
            $this->manager->connect($shop);
            $this->fail('A tenant database endpoint that drifted after validation was accepted.');
        } catch (TenantDatabaseAttestationFailed) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $driftedResolver->calls);
        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_mysql_dns_drift_between_validation_and_pdo_open_fails_closed(): void
    {
        $initialResolver = new class implements DatabaseHostResolver
        {
            public function resolve(string $host): array
            {
                return ['192.0.2.20'];
            }
        };
        $driftedResolver = new class implements DatabaseHostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                $this->calls++;

                return ['192.0.2.21'];
            }
        };
        app()->instance(DatabaseHostResolver::class, $initialResolver);
        $shop = Shop::registerForProvisioning(
            name: 'DNS handoff shop',
            slug: 'dns-handoff-shop',
            databaseDriver: 'mysql',
            databaseName: 'dns_handoff_shop',
            databaseHost: 'handoff-db.example.test',
        );
        app()->instance(TenantConnectionAttestationHook::class, new class($driftedResolver) implements TenantConnectionAttestationHook
        {
            public function __construct(private readonly DatabaseHostResolver $driftedResolver) {}

            public function beforeOpen(
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {
                app()->instance(DatabaseHostResolver::class, $this->driftedResolver);
            }

            public function afterOpen(
                PDO $pdo,
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {}

            public function afterSqliteNonceWritten(
                PDO $pdo,
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {}
        });

        try {
            $this->manager->connect($shop);
            $this->fail('DNS drift during the validation-to-PDO handoff was accepted.');
        } catch (TenantDatabaseAttestationFailed) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $driftedResolver->calls);
        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_mysql_endpoint_reattestation_canonicalizes_addresses_and_skips_unix_sockets(): void
    {
        $initialResolver = new class implements DatabaseHostResolver
        {
            public function resolve(string $host): array
            {
                return ['192.0.2.12'];
            }
        };
        $target = NormalizedDatabaseTarget::forTenant(
            target: new DatabaseTargetConfiguration(
                driver: 'mysql',
                database: 'endpoint_re_attestation',
                host: 'tenant-db.example.test',
            ),
            defaults: new DatabaseTargetConfiguration('mysql', 'ignored'),
            central: null,
            sqliteRoot: $this->tenantRoot,
            hostResolver: $initialResolver,
        );
        $sameEndpointResolver = new class implements DatabaseHostResolver
        {
            public function resolve(string $host): array
            {
                return ['::ffff:192.0.2.12'];
            }
        };
        $driftedEndpointResolver = new class implements DatabaseHostResolver
        {
            public function resolve(string $host): array
            {
                return ['192.0.2.13'];
            }
        };

        $this->assertTrue($target->hasCurrentMySqlEndpoints($sameEndpointResolver));
        $this->assertFalse($target->hasCurrentMySqlEndpoints($driftedEndpointResolver));
        $this->assertSame('tenant-db.example.test', $target->host);

        $socketResolver = new class implements DatabaseHostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                $this->calls++;

                throw new RuntimeException('Socket targets must not resolve a host.');
            }
        };
        $socketTarget = NormalizedDatabaseTarget::forTenant(
            target: new DatabaseTargetConfiguration(
                driver: 'mysql',
                database: 'socket_re_attestation',
                host: 'ignored.example.test',
                socket: $this->tenantRoot.DIRECTORY_SEPARATOR.'mysql.sock',
            ),
            defaults: new DatabaseTargetConfiguration('mysql', 'ignored'),
            central: null,
            sqliteRoot: $this->tenantRoot,
            hostResolver: $socketResolver,
        );

        $this->assertTrue($socketTarget->hasCurrentMySqlEndpoints($socketResolver));
        $this->assertSame(0, $socketResolver->calls);
    }

    public function test_sqlite_file_swap_after_validation_is_rejected_even_with_a_matching_marker(): void
    {
        $replacement = $this->tenantRoot.DIRECTORY_SEPARATOR.'replacement.sqlite';

        if (File::put($replacement, '') === false) {
            throw new RuntimeException('Unable to create replacement tenant database.');
        }

        $this->writeTenantMarker($replacement, $this->shopA);
        $original = (string) $this->shopA->database_name;
        $backup = $original.'.validated';
        app()->instance(TenantConnectionAttestationHook::class, new class($original, $backup, $replacement) implements TenantConnectionAttestationHook
        {
            public function __construct(
                private readonly string $original,
                private readonly string $backup,
                private readonly string $replacement,
            ) {}

            public function beforeOpen(
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {
                if (! rename($this->original, $this->backup)
                    || ! rename($this->replacement, $this->original)) {
                    throw new RuntimeException('Unable to swap the SQLite test database.');
                }
            }

            public function afterOpen(
                PDO $pdo,
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {}

            public function afterSqliteNonceWritten(
                PDO $pdo,
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {}
        });

        $this->expectException(TenantDatabaseAttestationFailed::class);

        $this->manager->connect($this->shopA);
    }

    public function test_sqlite_swap_open_restore_with_a_copied_marker_is_rejected(): void
    {
        $replacement = $this->tenantRoot.DIRECTORY_SEPARATOR.'copied-marker.sqlite';

        if (File::copy((string) $this->shopA->database_name, $replacement) === false) {
            throw new RuntimeException('Unable to copy the tenant database marker.');
        }

        $original = (string) $this->shopA->database_name;
        $backup = $original.'.registered';
        app()->instance(TenantConnectionAttestationHook::class, new class($original, $backup, $replacement) implements TenantConnectionAttestationHook
        {
            public function __construct(
                private readonly string $original,
                private readonly string $backup,
                private readonly string $replacement,
            ) {}

            public function beforeOpen(
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {
                if (! rename($this->original, $this->backup)
                    || ! rename($this->replacement, $this->original)) {
                    throw new RuntimeException('Unable to install the copied tenant database.');
                }
            }

            public function afterOpen(
                PDO $pdo,
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {
                $openedCopy = $this->original.'.opened-copy';

                if (! rename($this->original, $openedCopy) || ! rename($this->backup, $this->original)) {
                    throw new RuntimeException('Unable to restore the registered database after opening.');
                }
            }

            public function afterSqliteNonceWritten(
                PDO $pdo,
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {}
        });

        $this->expectException(TenantDatabaseAttestationFailed::class);

        $this->manager->connect($this->shopA);
    }

    public function test_sqlite_nonce_witness_rejects_a_copied_marker_on_an_unregistered_file(): void
    {
        $replacement = $this->tenantRoot.DIRECTORY_SEPARATOR.'copied-marker-witness.sqlite';

        if (File::copy((string) $this->shopA->database_name, $replacement) === false) {
            throw new RuntimeException('Unable to copy the tenant database marker.');
        }

        app()->instance(
            TenantConnectionAttestationHook::class,
            new class($replacement) implements TenantConnectionAttestationHook
            {
                public function __construct(private readonly string $replacement) {}

                public function beforeOpen(
                    #[\SensitiveParameter]
                    NormalizedDatabaseTarget $target,
                ): void {}

                public function afterOpen(
                    PDO $pdo,
                    #[\SensitiveParameter]
                    NormalizedDatabaseTarget $target,
                ): void {}

                public function afterSqliteNonceWritten(
                    PDO $pdo,
                    #[\SensitiveParameter]
                    NormalizedDatabaseTarget $target,
                ): void {
                    $nonce = $pdo
                        ->query('SELECT connection_nonce FROM tenant_installations WHERE id = 1')
                        ?->fetchColumn();

                    if (! is_string($nonce)) {
                        throw new RuntimeException('The registered tenant nonce was not written.');
                    }

                    $replacement = new PDO('sqlite:'.$this->replacement, options: [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]);
                    $statement = $replacement->prepare(
                        'UPDATE tenant_installations SET connection_nonce = ? WHERE id = ?',
                    );
                    $statement->execute([$nonce, 1]);
                }
            },
        );
        app()->instance(
            TenantSqliteWitnessConnection::class,
            new class($replacement) implements TenantSqliteWitnessConnection
            {
                public function __construct(private readonly string $replacement) {}

                public function open(
                    #[\SensitiveParameter]
                    NormalizedDatabaseTarget $target,
                ): PDO {
                    return new PDO('sqlite:'.$this->replacement, options: [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]);
                }
            },
        );
        $this->app->forgetInstance(TenantConnectionManager::class);
        $this->manager = app(TenantConnectionManager::class);

        try {
            $this->manager->connect($this->shopA);
            $this->fail('A copied-marker witness on an unregistered SQLite file was accepted.');
        } catch (TenantDatabaseAttestationFailed) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_sqlite_nonce_challenges_are_serialized_across_workers(): void
    {
        $database = (string) $this->shopA->database_name;
        $lockDirectory = (string) config('database.tenant_attestation_lock_path');
        $waitingPath = $this->tenantRoot.DIRECTORY_SEPARATOR.'second-worker-waiting';
        $completedPath = $this->tenantRoot.DIRECTORY_SEPARATOR.'second-worker-completed';
        $hook = new class($database, $lockDirectory, $waitingPath, $completedPath) implements TenantConnectionAttestationHook
        {
            public ?Process $process = null;

            public function __construct(
                private readonly string $database,
                private readonly string $lockDirectory,
                private readonly string $waitingPath,
                private readonly string $completedPath,
            ) {}

            public function beforeOpen(
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {}

            public function afterOpen(
                PDO $pdo,
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {}

            public function afterSqliteNonceWritten(
                PDO $pdo,
                #[\SensitiveParameter]
                NormalizedDatabaseTarget $target,
            ): void {
                $lockIdentity = $target->locatorFingerprint ?? $target->fingerprint;
                $lockPath = $this->lockDirectory.DIRECTORY_SEPARATOR.hash('sha256', $lockIdentity).'.lock';
                $script = <<<'PHP'
$database = $argv[1];
$lockPath = $argv[2];
$waitingPath = $argv[3];
$completedPath = $argv[4];
file_put_contents($waitingPath, 'waiting');
$lock = fopen($lockPath, 'c+b');

if ($lock === false || ! flock($lock, LOCK_EX)) {
    throw new RuntimeException('The second worker could not acquire the attestation lock.');
}

try {
    $pdo = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $nonce = bin2hex(random_bytes(32));
    $statement = $pdo->prepare('UPDATE tenant_installations SET connection_nonce = ? WHERE id = ?');
    $statement->execute([$nonce, 1]);
    $witness = new PDO('sqlite:'.$database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $observedNonce = $witness->query('SELECT connection_nonce FROM tenant_installations WHERE id = 1')?->fetchColumn();

    if (! is_string($observedNonce) || ! hash_equals($nonce, $observedNonce)) {
        throw new RuntimeException('The second worker observed an interleaved nonce.');
    }

    $pdo->exec('UPDATE tenant_installations SET connection_nonce = NULL WHERE id = 1');
    file_put_contents($completedPath, 'completed');
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
PHP;
                $this->process = new Process([
                    PHP_BINARY,
                    '-r',
                    $script,
                    $this->database,
                    $lockPath,
                    $this->waitingPath,
                    $this->completedPath,
                ]);
                $this->process->setTimeout(10);
                $this->process->start();
                $deadline = microtime(true) + 2;

                while (! is_file($this->waitingPath)
                    && $this->process->isRunning()
                    && microtime(true) < $deadline) {
                    usleep(10_000);
                }

                if (! is_file($this->waitingPath)) {
                    throw new RuntimeException('The second attestation worker did not start.');
                }

                usleep(200_000);

                if (! $this->process->isRunning()) {
                    throw new RuntimeException(
                        'A second attestation worker entered during the first nonce challenge. '
                        .$this->process->getErrorOutput(),
                    );
                }
            }
        };
        app()->instance(TenantConnectionAttestationHook::class, $hook);

        $this->manager->connect($this->shopA);
        $this->assertInstanceOf(Process::class, $hook->process);
        $hook->process->wait();

        $this->assertTrue(
            $hook->process->isSuccessful(),
            $hook->process->getErrorOutput(),
        );
        $this->assertFileExists($completedPath);
        $this->assertNull(
            DB::connection('tenant')->table('tenant_installations')->where('id', 1)->value('connection_nonce'),
        );
    }

    public function test_wrong_tenant_marker_hmac_is_rejected(): void
    {
        $connection = DB::build($this->sqliteConfiguration((string) $this->shopA->database_name));

        try {
            $connection->table('tenant_installations')->where('id', 1)->update([
                'attestation_hmac' => hash('sha256', 'forged marker'),
            ]);
        } finally {
            DB::purge($connection->getName());
        }

        $this->expectException(TenantDatabaseAttestationFailed::class);

        $this->manager->connect($this->shopA);
    }

    public function test_explicit_reconnect_revalidates_and_reattests_before_models_remain_usable(): void
    {
        $this->manager->connect($this->shopA);
        $firstPdo = DB::connection('tenant')->getPdo();
        $reconnected = DB::reconnect('tenant');

        $this->assertNotSame($firstPdo, $reconnected->getPdo());
        $this->assertSame($this->shopA->getKey(), app(TenantContext::class)->id());
        $this->assertSame(0, Item::query()->count());
        $this->replaceMarkerShopId($this->shopA, (string) Str::uuid());

        try {
            DB::reconnect('tenant');
            $this->fail('An explicitly reconnected PDO bypassed tenant attestation.');
        } catch (TenantDatabaseAttestationFailed) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->expectException(TenantNotInitialized::class);

        Item::query()->count();
    }

    public function test_missing_pdo_reconnect_is_manager_owned_and_reattested(): void
    {
        $this->manager->connect($this->shopA);
        $connection = DB::connection('tenant');
        $connection->disconnect();

        $this->assertSame(0, Item::query()->count());
        $this->assertSame($this->shopA->getKey(), app(TenantContext::class)->id());

        $connection->disconnect();
        $this->replaceMarkerShopId($this->shopA, (string) Str::uuid());

        try {
            Item::query()->count();
            $this->fail('A lost-connection-style reconnect bypassed marker attestation.');
        } catch (TenantDatabaseAttestationFailed) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse(app(TenantContext::class)->initialized());
    }

    public function test_purge_then_lookup_revalidates_and_reattests_the_new_pdo(): void
    {
        $this->manager->connect($this->shopA);
        DB::purge('tenant');

        $this->assertSame(0, DB::connection('tenant')->table('items')->count());
        $this->assertSame($this->shopA->getKey(), app(TenantContext::class)->id());

        DB::purge('tenant');
        $this->replaceMarkerShopId($this->shopA, (string) Str::uuid());

        $this->expectException(TenantDatabaseAttestationFailed::class);

        DB::connection('tenant')->getPdo();
    }

    public function test_models_loaded_under_one_shop_cannot_touch_colliding_rows_after_a_switch(): void
    {
        $this->manager->connect($this->shopA);
        $shopAItem = Item::factory()->create(['name' => 'Shop A item']);
        $shopAItem->load('vehicleCompatibilities');
        $shopAId = $shopAItem->getKey();
        $shopAQuery = Item::query()->whereKey($shopAId);
        $this->manager->disconnect();

        $this->manager->connect($this->shopB);
        $shopBItem = Item::factory()->create(['name' => 'Shop B item']);
        $this->assertSame($shopAId, $shopBItem->getKey());

        foreach ([
            static fn () => $shopAItem->update(['name' => 'Cross-tenant update']),
            static fn () => $shopAItem->delete(),
            static fn () => $shopAItem->refresh(),
            static fn () => $shopAItem->vehicleCompatibilities()->count(),
            static fn () => $shopAItem->vehicleCompatibilities,
            static fn () => $shopAItem->replicate()->save(),
            static fn () => $shopAItem->replicateQuietly()->save(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('A stale operational model crossed into the active Shop.');
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Operational models cannot cross tenant contexts.',
                    $exception->getMessage(),
                );
            }
        }

        try {
            $shopAQuery->update(['name' => 'Stale builder update']);
            $this->fail('A stale tenant query reconnected against the active Shop.');
        } catch (LogicException $exception) {
            $this->assertContains(
                $exception->getMessage(),
                [
                    'Operational models cannot cross tenant contexts.',
                    'A stale tenant connection cannot be reconnected.',
                ],
            );
        }

        $this->assertSame('Shop B item', $shopBItem->fresh()->name);
        $this->assertSame(1, Item::query()->count());
        $this->manager->disconnect();
        $this->manager->connect($this->shopA);
        $this->assertSame('Shop A item', Item::query()->findOrFail($shopAId)->name);
        $this->assertSame(1, Item::query()->count());
    }

    public function test_disconnect_revokes_the_tenant_before_a_rollback_callback_can_fail(): void
    {
        $this->manager->connect($this->shopA);
        $connection = DB::connection('tenant');
        $connection->beginTransaction();
        $connection->afterRollBack(static function (): never {
            throw new RuntimeException('forced rollback callback failure');
        });

        $this->manager->disconnect();

        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
        $this->assertArrayNotHasKey('tenant', DB::getConnections());
        $this->assertNull($connection->getRawPdo());

        $this->expectException(LogicException::class);
        $connection->reconnect();
    }

    public function test_module_registry_cache_is_flushed_on_every_tenant_boundary(): void
    {
        $registry = app(ModuleRegistry::class);
        $this->manager->connect($this->shopA);
        ModuleSetting::query()->create(['key' => 'scripts', 'enabled' => true]);
        $this->manager->disconnect();
        $this->manager->connect($this->shopB);
        ModuleSetting::query()->create(['key' => 'scripts', 'enabled' => false]);
        $this->manager->disconnect();

        $this->manager->connect($this->shopA);
        $this->assertTrue($registry->enabled('scripts'));
        $this->manager->disconnect();
        $this->manager->connect($this->shopB);
        $this->assertFalse($registry->enabled('scripts'));
        $this->manager->disconnect();

        $this->expectException(TenantNotInitialized::class);
        $registry->enabled('scripts');
    }

    public function test_disconnect_purges_dynamic_configuration_and_cannot_reconnect_the_last_shop(): void
    {
        $this->manager->connect($this->shopA);
        $this->assertSame((string) $this->shopA->database_name, DB::connection('tenant')->getDatabaseName());

        $this->manager->disconnect();

        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
        $this->assertSame(
            'spatie.permission.cache.no-tenant',
            app(PermissionRegistrar::class)->cacheKey,
        );

        try {
            DB::connection('tenant')->getPdo();
            $this->fail('The purged tenant connection reached the previous shop.');
        } catch (Throwable $exception) {
            $this->assertStringNotContainsString((string) $this->shopA->database_name, $exception->getMessage());
        }
    }

    public function test_within_owns_cleanup_and_rejects_a_nested_different_shop(): void
    {
        $result = $this->manager->within($this->shopA, function (): string {
            $this->assertSame($this->shopA->getKey(), app(TenantContext::class)->id());

            try {
                $this->manager->within($this->shopB, static fn (): null => null);
                $this->fail('A nested different-tenant scope was accepted.');
            } catch (LogicException $exception) {
                $this->assertSame('A different tenant is already initialized.', $exception->getMessage());
            }

            return 'completed';
        });

        $this->assertSame('completed', $result);
        $this->assertFalse(app(TenantContext::class)->initialized());
        $this->assertFalse(config()->has('database.connections.tenant'));
    }

    public function test_spatie_models_pivots_and_cache_are_isolated_across_a_b_a_switches(): void
    {
        $this->manager->connect($this->shopA);
        $permission = Permission::findOrCreate('tenant-a-only', 'web');
        $role = Role::findOrCreate('shared-role-name', 'web');
        $role->givePermissionTo($permission);
        $user = User::factory()->create();
        $user->assignRole($role);
        $shopAUserId = $user->getKey();
        $shopACacheKey = (string) config('permission.cache.key');
        $shopARolePermissionPivot = $role->permissions()->firstOrFail()->pivot;
        $shopAUserRolePivot = $user->roles()->firstOrFail()->pivot;
        $shopARolePermissionCount = DB::connection('tenant')->table('role_has_permissions')->count();
        $shopAUserRoleCount = DB::connection('tenant')->table('model_has_roles')->count();

        $this->assertTrue($user->fresh()->hasPermissionTo('tenant-a-only'));
        $this->assertSame('tenant', $user->roles()->firstOrFail()->getConnectionName());
        $this->manager->disconnect();

        $this->manager->connect($this->shopB);
        $this->assertNotSame($shopACacheKey, config('permission.cache.key'));
        $this->assertFalse(Permission::query()->where('name', 'tenant-a-only')->exists());
        $this->assertFalse(Role::query()->where('name', 'shared-role-name')->exists());
        $shopBPermission = Permission::findOrCreate('tenant-b-only', 'web');
        $shopBRole = Role::findOrCreate('shared-role-name', 'web');
        $shopBRole->givePermissionTo($shopBPermission);
        $shopBUser = User::factory()->create();
        $shopBUser->assignRole($shopBRole);
        $shopBRolePermissionCount = DB::connection('tenant')->table('role_has_permissions')->count();
        $shopBUserRoleCount = DB::connection('tenant')->table('model_has_roles')->count();

        foreach ([$shopARolePermissionPivot, $shopAUserRolePivot] as $shopAPivot) {
            try {
                $shopAPivot->delete();
                $this->fail('A stale tenant pivot deleted a colliding Shop B assignment.');
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Operational models cannot cross tenant contexts.',
                    $exception->getMessage(),
                );
            }
        }

        $this->assertSame($shopBRolePermissionCount, DB::connection('tenant')->table('role_has_permissions')->count());
        $this->assertSame($shopBUserRoleCount, DB::connection('tenant')->table('model_has_roles')->count());
        $this->manager->disconnect();

        $this->manager->connect($this->shopA);
        $this->assertTrue(User::query()->findOrFail($shopAUserId)->hasPermissionTo('tenant-a-only'));
        $this->assertSame($shopACacheKey, config('permission.cache.key'));
        $this->assertSame($shopARolePermissionCount, DB::connection('tenant')->table('role_has_permissions')->count());
        $this->assertSame($shopAUserRoleCount, DB::connection('tenant')->table('model_has_roles')->count());
    }

    public function test_stale_reconnect_exception_traces_do_not_retain_connection_credentials(): void
    {
        $credential = 'tenant-password-sentinel-'.Str::random(24);
        $shop = $this->createMigratedTenant('trace-secret-shop', $credential);
        $this->manager->connect($shop);
        $connection = DB::connection('tenant');
        $this->manager->disconnect();
        $previousSetting = ini_set('zend.exception_ignore_args', '0');

        try {
            try {
                $connection->reconnect();
                $this->fail('A stale tenant connection was reconnected.');
            } catch (LogicException $exception) {
                $this->assertFalse($this->valueContainsSecret($exception->getTrace(), $credential));
            }
        } finally {
            if ($previousSetting !== false) {
                ini_set('zend.exception_ignore_args', $previousSetting);
            }
        }
    }

    public function test_model_backed_validation_reads_only_the_active_tenant(): void
    {
        $this->manager->connect($this->shopA);
        Item::factory()->create(['name' => 'Tenant-scoped unique name']);
        $this->manager->disconnect();

        $this->manager->connect($this->shopB);
        $rules = (new QuickItemRequest)->rules();
        $attributes = ['name' => 'Tenant-scoped unique name', 'type' => ItemType::Repair->value];

        $this->assertTrue(Validator::make($attributes, $rules)->passes());

        Item::factory()->repair()->create(['name' => 'Tenant-scoped unique name']);

        $this->assertTrue(Validator::make($attributes, $rules)->fails());
    }

    public function test_model_owned_transaction_writes_only_to_the_active_tenant(): void
    {
        $this->manager->connect($this->shopA);
        $this->actingAs(User::factory()->create());

        $sale = (new RecordSale)([
            'lines' => [[
                'type' => SaleLineType::Custom->value,
                'item_id' => null,
                'item_name' => 'Tenant transaction line',
                'quantity' => 1,
                'manually_charged_price' => '125.00',
            ]],
        ]);

        $this->assertSame('tenant', $sale->getConnectionName());
        $this->assertSame('Tenant transaction line', $sale->lines()->firstOrFail()->item_name);
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('sales'));
    }

    public function test_model_owned_transaction_rolls_back_tenant_writes(): void
    {
        $this->manager->connect($this->shopA);
        $this->actingAs(User::factory()->create());
        $eventName = 'eloquent.created: '.SaleItem::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Forced tenant transaction failure.');
        });

        try {
            (new RecordSale)([
                'lines' => [[
                    'type' => SaleLineType::Custom->value,
                    'item_id' => null,
                    'item_name' => 'Rolled back tenant line',
                    'quantity' => 1,
                    'manually_charged_price' => '125.00',
                ]],
            ]);
            $this->fail('The forced tenant transaction failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced tenant transaction failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, SaleItem::query()->count());
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('sales'));
    }

    public function test_supplier_payment_transaction_uses_only_the_active_tenant_connection(): void
    {
        $this->manager->connect($this->shopA);
        $user = User::factory()->create();
        $supply = Supply::factory()->create(['total_amount' => '1000.00']);

        $payment = (new RecordSupplierPayment)($supply, $user, [
            'amount' => '250.00',
            'method' => 'cash',
            'paid_at' => now(),
            'reference_number' => 'TENANT-PAYMENT',
        ]);

        $this->assertSame('tenant', $payment->getConnectionName());
        $this->assertSame('250.00', $payment->amount);
        $this->assertSame($payment->getKey(), Expense::query()->sole()->supplier_payment_id);
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('supplier_payments'));
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('expenses'));
    }

    public function test_supplier_payment_transaction_rolls_back_tenant_writes_after_an_expense_insert_fails(): void
    {
        $this->manager->connect($this->shopA);
        $user = User::factory()->create();
        $supply = Supply::factory()->create(['total_amount' => '1000.00']);
        $activityLogCount = ActivityLog::query()->count();
        $eventName = 'eloquent.created: '.Expense::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Forced supplier payment transaction failure.');
        });

        try {
            (new RecordSupplierPayment)($supply, $user, [
                'amount' => '250.00',
                'method' => 'cash',
                'paid_at' => now(),
                'reference_number' => 'ROLLBACK-PAYMENT',
            ]);
            $this->fail('The forced supplier payment transaction failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced supplier payment transaction failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(0, SupplierPayment::query()->count());
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame($activityLogCount, ActivityLog::query()->count());
    }

    public function test_quick_item_transaction_rolls_back_tenant_write_after_the_item_insert_fails(): void
    {
        $this->manager->connect($this->shopA);
        $user = User::factory()->create();
        $this->actingAs($user);
        $activityLogCount = ActivityLog::query()->count();
        $request = $this->validatedFormRequest(QuickItemRequest::class, [
            'name' => 'Rolled back quick repair',
            'type' => ItemType::Repair->value,
        ], $user);
        $eventName = 'eloquent.created: '.Item::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Forced quick item transaction failure.');
        });

        try {
            app(QuickItemController::class)->store($request);
            $this->fail('The forced quick item transaction failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced quick item transaction failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(0, Item::query()->count());
        $this->assertSame($activityLogCount, ActivityLog::query()->count());
    }

    public function test_inspection_creation_transaction_rolls_back_after_a_point_insert_fails(): void
    {
        $this->manager->connect($this->shopA);
        $user = User::factory()->create();
        $this->actingAs($user);
        $activityLogCount = ActivityLog::query()->count();
        $request = $this->validatedFormRequest(InspectionRequest::class, [
            'vehicle_plate' => 'ROLL-101',
            'vehicle_model' => 'Rollback vehicle',
            'mileage' => 1000,
            'points' => ['engine_oil' => ['status' => 'ok']],
        ], $user);
        $eventName = 'eloquent.created: '.InspectionItem::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Forced inspection creation transaction failure.');
        });

        try {
            app(InspectionController::class)->store($request);
            $this->fail('The forced inspection creation transaction failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced inspection creation transaction failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(0, Inspection::query()->count());
        $this->assertSame(0, InspectionItem::query()->count());
        $this->assertSame($activityLogCount, ActivityLog::query()->count());
    }

    public function test_inspection_update_transaction_restores_the_original_sheet_after_a_point_insert_fails(): void
    {
        $this->manager->connect($this->shopA);
        $user = User::factory()->create();
        $this->actingAs($user);
        $inspection = Inspection::factory()->create([
            'vehicle_plate' => 'ROLL-201',
            'vehicle_model' => 'Original vehicle',
            'mileage' => 2000,
        ]);
        $inspection->syncPoints(['engine_oil' => ['status' => 'ok']]);
        $request = $this->validatedFormRequest(InspectionRequest::class, [
            'vehicle_plate' => 'ROLL-202',
            'vehicle_model' => 'Changed vehicle',
            'mileage' => 3000,
            'points' => ['battery' => ['status' => 'urgent']],
        ], $user, method: 'PUT');
        $eventName = 'eloquent.created: '.InspectionItem::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Forced inspection update transaction failure.');
        });

        try {
            app(InspectionController::class)->update($request, $inspection);
            $this->fail('The forced inspection update transaction failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced inspection update transaction failure.', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $inspection = $inspection->fresh();

        $this->assertInstanceOf(Inspection::class, $inspection);
        $this->assertSame('ROLL-201', $inspection->vehicle_plate);
        $this->assertSame('Original vehicle', $inspection->vehicle_model);
        $this->assertSame(2000, $inspection->mileage);
        $this->assertSame('engine_oil', $inspection->points()->sole()->point->value);
        $this->assertSame('ok', $inspection->points()->sole()->status->value);
    }

    public function test_quick_item_and_inspection_write_transactions_use_only_the_active_tenant(): void
    {
        $this->manager->connect($this->shopA);
        URL::defaults(['tenant' => $this->shopA->slug]);
        $user = User::factory()->create();
        $this->actingAs($user);
        $quickItemRequest = $this->validatedFormRequest(QuickItemRequest::class, [
            'name' => 'Tenant-only quick repair',
            'type' => ItemType::Repair->value,
        ], $user);

        app(QuickItemController::class)->store($quickItemRequest);

        $inspectionRequest = $this->validatedFormRequest(InspectionRequest::class, [
            'vehicle_plate' => 'TEN-101',
            'vehicle_model' => 'Tenant vehicle',
            'mileage' => 1000,
            'points' => ['engine_oil' => ['status' => 'ok']],
        ], $user);
        app(InspectionController::class)->store($inspectionRequest);
        $inspection = Inspection::query()->sole();
        $updateRequest = $this->validatedFormRequest(InspectionRequest::class, [
            'vehicle_plate' => 'TEN-202',
            'vehicle_model' => 'Updated tenant vehicle',
            'mileage' => 2000,
            'points' => ['battery' => ['status' => 'urgent']],
        ], $user, method: 'PUT');
        app(InspectionController::class)->update($updateRequest, $inspection);

        $this->assertDatabaseHas('items', ['name' => 'Tenant-only quick repair'], 'tenant');
        $this->assertSame('TEN-202', $inspection->fresh()->vehicle_plate);
        $this->assertSame('battery', $inspection->fresh()->points()->sole()->point->value);
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('items'));
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('inspections'));
    }

    public function test_all_audited_model_validation_rules_read_only_the_active_tenant(): void
    {
        $this->manager->connect($this->shopA);
        Item::factory()->create(['name' => 'Only in validation shop A']);
        $shopASaleId = Sale::factory()->create()->getKey();
        $shopAMake = VehicleMake::factory()->create();
        $shopAModel = VehicleModel::factory()->for($shopAMake)->create();
        $this->manager->disconnect();

        $this->manager->connect($this->shopB);
        $itemRules = (new ItemRequest)->rules();
        $inspectionRules = (new InspectionRequest)->rules();
        $quickItemRules = (new QuickItemRequest)->rules();

        $this->assertTrue(Validator::make([
            'name' => 'Only in validation shop A',
            'type' => ItemType::Product->value,
            'unit_of_measure' => 'piece',
        ], $itemRules)->passes());
        $this->assertTrue(Validator::make([
            'sale_id' => $shopASaleId,
            'vehicle_plate' => 'VAL-101',
            'points' => ['engine_oil' => ['status' => 'ok']],
        ], $inspectionRules)->fails());
        $this->assertTrue(Validator::make([
            'name' => 'Validation product',
            'type' => ItemType::Product->value,
            'is_universal' => false,
            'compatibilities' => [[
                'vehicle_make_id' => $shopAMake->getKey(),
                'vehicle_model_id' => $shopAModel->getKey(),
            ]],
        ], $quickItemRules)->fails());

        $shopBSale = Sale::factory()->create();
        $shopBMake = VehicleMake::factory()->create();
        $shopBModel = VehicleModel::factory()->for($shopBMake)->create();
        $this->assertSame($shopASaleId, $shopBSale->getKey());
        $this->assertSame($shopAMake->getKey(), $shopBMake->getKey());
        $this->assertSame($shopAModel->getKey(), $shopBModel->getKey());
        $this->assertTrue(Validator::make([
            'sale_id' => $shopBSale->getKey(),
            'vehicle_plate' => 'VAL-202',
            'points' => ['engine_oil' => ['status' => 'ok']],
        ], $inspectionRules)->passes());
        $this->assertTrue(Validator::make([
            'name' => 'Validation product',
            'type' => ItemType::Product->value,
            'is_universal' => false,
            'compatibilities' => [[
                'vehicle_make_id' => $shopBMake->getKey(),
                'vehicle_model_id' => $shopBModel->getKey(),
            ]],
        ], $quickItemRules)->passes());
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('items'));
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('sales'));
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('vehicle_models'));
    }

    private function createMigratedTenant(
        string $slug,
        #[\SensitiveParameter]
        ?string $databasePassword = null,
    ): Shop {
        $database = $this->tenantRoot.DIRECTORY_SEPARATOR.$slug.'.sqlite';

        $shop = Shop::registerForProvisioning(
            name: str($slug)->headline()->toString(),
            slug: $slug,
            databaseDriver: 'sqlite',
            databaseName: $database,
            databasePassword: $databasePassword,
        );
        $this->createMigratedTenantDatabase($shop);

        return $shop;
    }

    /**
     * @template TRequest of \Illuminate\Foundation\Http\FormRequest
     *
     * @param  class-string<TRequest>  $requestClass
     * @param  array<string, mixed>  $data
     * @return TRequest
     */
    private function validatedFormRequest(
        string $requestClass,
        array $data,
        User $user,
        string $method = 'POST',
    ): FormRequest {
        $request = $requestClass::create('/', $method, $data);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->setUserResolver(static fn (): User => $user);
        $request->validateResolved();

        return $request;
    }

    private function writeTenantMarker(string $database, Shop $shop): void
    {
        $this->installTenantDatabaseMarker($database, $shop);
    }

    private function replaceMarkerShopId(Shop $shop, string $shopId): void
    {
        $connection = DB::build($this->sqliteConfiguration((string) $shop->database_name));

        try {
            $connection->table('tenant_installations')->where('id', 1)->update(['shop_id' => $shopId]);
        } finally {
            DB::purge($connection->getName());
        }
    }

    /** @return array<string, mixed> */
    private function sqliteConfiguration(string $database): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'transaction_mode' => 'DEFERRED',
        ];
    }

    private function valueContainsSecret(
        mixed $value,
        #[\SensitiveParameter]
        string $secret,
        int $depth = 0,
        ?\SplObjectStorage $seen = null,
    ): bool {
        if ($depth > 12) {
            return false;
        }

        if (is_string($value)) {
            return str_contains($value, $secret);
        }

        if (is_array($value)) {
            foreach ($value as $nestedValue) {
                if ($this->valueContainsSecret($nestedValue, $secret, $depth + 1, $seen)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_object($value) || $value instanceof \SensitiveParameterValue) {
            return false;
        }

        $seen ??= new \SplObjectStorage;

        if ($seen->contains($value)) {
            return false;
        }

        $seen->attach($value);

        return $this->valueContainsSecret(
            get_mangled_object_vars($value),
            $secret,
            $depth + 1,
            $seen,
        );
    }

    private function newTemporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.Str::uuid();

        if (! File::makeDirectory($directory, 0700, true)) {
            throw new RuntimeException('Unable to create tenant test directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
