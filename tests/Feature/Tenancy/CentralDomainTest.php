<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Tenancy\RecordShopLifecycleActivity;
use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Exceptions\TenantDatabaseTargetConflict;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopAccessSession;
use App\Models\Central\ShopDatabaseTargetClaim;
use App\Models\Central\ShopFeature;
use App\Models\Central\ShopHealthSnapshot;
use App\Models\Central\ShopLifecycleActivity;
use App\Models\Central\ShopOwner;
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\DatabaseTargetConfiguration;
use App\Tenancy\NormalizedDatabaseTarget;
use App\Tenancy\SqliteDatabaseIdentitySnapshot;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class CentralDomainTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryDatabasePaths = [];

    /** @var array<int, string> */
    private array $temporaryDirectories = [];

    private string $tenantDatabaseRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDatabaseRoot = $this->newTemporaryDirectory('tenant-databases-');
        config()->set('database.tenant_sqlite_root', $this->tenantDatabaseRoot);

        $centralDatabasePath = $this->newTemporaryDatabasePath('central-domain-');

        config()->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => $centralDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'transaction_mode' => 'DEFERRED',
        ]);
        DB::purge('central');

        Artisan::call('migrate', [
            '--database' => 'central',
            '--path' => 'database/migrations/central',
            '--realpath' => false,
            '--no-interaction' => true,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('central');
            File::delete($this->temporaryDatabasePaths);

            foreach ($this->temporaryDirectories as $temporaryDirectory) {
                File::deleteDirectory($temporaryDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_central_models_never_use_the_tenant_connection(): void
    {
        $shop = Shop::factory()->create(['status' => ShopStatus::Active]);

        $this->assertSame('central', $shop->getConnectionName());
        $this->assertSame('central', (new PlatformUser)->getConnectionName());
        $this->assertSame('central', (new ShopOwner)->getConnectionName());
        $this->assertSame('central', (new ShopFeature)->getConnectionName());
        $this->assertSame('central', (new ShopAccessSession)->getConnectionName());
        $this->assertSame('central', (new ShopHealthSnapshot)->getConnectionName());
        $this->assertSame('central', (new ShopLifecycleActivity)->getConnectionName());
        $this->assertSame('central', (new ShopDatabaseTargetClaim)->getConnectionName());
        $this->assertSame('active', $shop->status->value);
        $this->assertTrue(Str::isUuid($shop->getKey()));
    }

    public function test_central_relationships_persist_on_the_central_connection(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();
        $owner = $shop->owner()->create([
            'name' => 'Workshop Owner',
            'username' => 'workshop-owner',
            'email' => 'owner@example.com',
            'is_active' => true,
        ]);
        $feature = $shop->features()->create([
            'module_key' => 'inventory',
            'enabled' => true,
        ]);
        $accessSession = ShopAccessSession::start(
            platformUser: $platformUser,
            shop: $shop,
            reason: 'Investigate inventory totals',
        );
        $healthSnapshot = $shop->healthSnapshot()->create([
            'migration_status' => 'current',
            'summary' => ['inventory_count' => 12],
        ]);
        $databaseTargetClaim = $shop->databaseTargetClaims()->firstOrFail();

        $this->assertTrue($shop->owner()->firstOrFail()->is($owner));
        $this->assertTrue($shop->features()->firstOrFail()->is($feature));
        $this->assertTrue($shop->accessSessions()->firstOrFail()->is($accessSession));
        $this->assertTrue($shop->healthSnapshot()->firstOrFail()->is($healthSnapshot));
        $this->assertTrue($owner->shop()->firstOrFail()->is($shop));
        $this->assertTrue($feature->shop()->firstOrFail()->is($shop));
        $this->assertTrue($accessSession->shop()->firstOrFail()->is($shop));
        $this->assertTrue($accessSession->platformUser()->firstOrFail()->is($platformUser));
        $this->assertTrue($healthSnapshot->shop()->firstOrFail()->is($shop));
        $this->assertTrue($databaseTargetClaim->shop()->firstOrFail()->is($shop));
        $this->assertArrayNotHasKey('fingerprint', $databaseTargetClaim->toArray());
        $this->assertTrue($platformUser->shopAccessSessions()->firstOrFail()->is($accessSession));
    }

    public function test_central_sqlite_connection_enforces_foreign_keys(): void
    {
        $centralForeignKeys = DB::connection('central')->selectOne('PRAGMA foreign_keys')->foreign_keys;

        $this->assertSame(1, $centralForeignKeys);
    }

    public function test_central_database_remains_distinct_from_the_default_connection(): void
    {
        $shop = Shop::factory()->create();

        $this->assertDatabaseHas('shops', ['id' => $shop->getKey()], 'central');
        $this->assertFalse(Schema::connection(config('database.default'))->hasTable('shops'));
        $this->assertNotSame(
            DB::connection(config('database.default'))->getDatabaseName(),
            DB::connection('central')->getDatabaseName(),
        );
    }

    public function test_shop_connection_overrides_are_not_serialized(): void
    {
        $shop = Shop::factory()->create([
            'database_host' => 'db.internal',
            'database_port' => 3307,
            'database_username' => 'shop-user',
            'database_password' => 'secret-password',
        ]);

        $arrayPayload = $shop->toArray();
        $jsonPayload = json_decode($shop->toJson(), true, flags: JSON_THROW_ON_ERROR);

        foreach (['database_host', 'database_port', 'database_username', 'database_password'] as $field) {
            $this->assertArrayNotHasKey($field, $arrayPayload);
            $this->assertArrayNotHasKey($field, $jsonPayload);
        }
    }

    public function test_plaintext_connection_credentials_are_sensitive_parameters(): void
    {
        foreach (['registerForProvisioning', 'updateProvisioningTarget'] as $methodName) {
            $parameters = collect((new ReflectionMethod(Shop::class, $methodName))->getParameters())
                ->keyBy(static fn (ReflectionParameter $parameter): string => $parameter->getName());

            foreach ([
                'databaseDriver',
                'databaseName',
                'databaseHost',
                'databasePort',
                'databaseUsername',
                'databasePassword',
                'databaseSocket',
            ] as $parameterName) {
                $this->assertCount(
                    1,
                    $parameters->get($parameterName)?->getAttributes(\SensitiveParameter::class) ?? [],
                );
            }
        }

        $credentialParameters = (new ReflectionMethod(
            Shop::class,
            'setProvisioningCredentials',
        ))->getParameters();
        $encryptedSetterValue = (new ReflectionMethod(Shop::class, 'setEncryptedAttribute'))->getParameters()[1];
        $encryptedComparisonValue = (new ReflectionMethod(
            Shop::class,
            'encryptedAttributeMatches',
        ))->getParameters()[1];

        foreach ($credentialParameters as $parameter) {
            $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
        }

        $this->assertCount(1, $encryptedSetterValue->getAttributes(\SensitiveParameter::class));
        $this->assertCount(1, $encryptedComparisonValue->getAttributes(\SensitiveParameter::class));
    }

    public function test_database_target_normalizer_accepts_only_identity_configuration(): void
    {
        $parameters = (new ReflectionMethod(NormalizedDatabaseTarget::class, 'forTenant'))->getParameters();

        $this->assertSame(
            ['target', 'defaults', 'central', 'sqliteRoot', 'hostResolver'],
            array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters),
        );
        $this->assertSame('App\\Tenancy\\DatabaseTargetConfiguration', (string) $parameters[0]->getType());
        $this->assertSame('App\\Tenancy\\DatabaseTargetConfiguration', (string) $parameters[1]->getType());
        $this->assertSame('?App\\Tenancy\\DatabaseTargetConfiguration', (string) $parameters[2]->getType());
    }

    public function test_system_host_resolution_cache_is_scoped_to_one_identity_evaluation(): void
    {
        $this->assertNotSame(
            resolve(DatabaseHostResolver::class),
            resolve(DatabaseHostResolver::class),
        );
    }

    public function test_laravel_connection_configuration_is_reduced_to_sensitive_identity_input(): void
    {
        $identity = DatabaseTargetConfiguration::fromLaravelConfiguration([
            'driver' => 'mysql',
            'url' => 'mysql://identity-user:identity-password@db.example.test:3307/Tenant_Identity',
            'username' => 'fallback-user',
            'password' => 'fallback-password',
        ]);
        $configurationParameter = (new ReflectionMethod(
            DatabaseTargetConfiguration::class,
            'fromLaravelConfiguration',
        ))->getParameters()[0];

        $this->assertSame([
            'driver' => 'mysql',
            'database' => 'Tenant_Identity',
            'host' => 'db.example.test',
            'port' => 3307,
            'socket' => null,
        ], get_object_vars($identity));
        $this->assertStringNotContainsString(
            'identity-password',
            json_encode($identity, JSON_THROW_ON_ERROR),
        );
        $this->assertCount(1, $configurationParameter->getAttributes(\SensitiveParameter::class));
    }

    public function test_malformed_database_urls_do_not_expose_credentials_in_exception_traces(): void
    {
        $previousIgnoreArguments = ini_get('zend.exception_ignore_args');
        $caughtException = null;
        ini_set('zend.exception_ignore_args', '0');

        try {
            DatabaseTargetConfiguration::fromLaravelConfiguration([
                'url' => 'mysql://trace-user:trace-only-password@',
            ]);
        } catch (Throwable $exception) {
            $caughtException = $exception;
        } finally {
            if (is_string($previousIgnoreArguments)) {
                ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
            }
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $caughtException);
        $traceContainsSecret = function (mixed $value) use (&$traceContainsSecret): bool {
            if (is_string($value)) {
                return str_contains($value, 'trace-only-password');
            }

            if (! is_array($value)) {
                return false;
            }

            foreach ($value as $nestedValue) {
                if ($traceContainsSecret($nestedValue)) {
                    return true;
                }
            }

            return false;
        };

        $this->assertFalse($traceContainsSecret($caughtException->getTrace()));
    }

    public function test_provisioning_target_credentials_are_not_captured_by_trace_callables(): void
    {
        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_trace_before_update',
            databaseHost: '192.0.2.94',
        );
        $shop->markProvisioningFailed('Prepare a target edit.');
        $secret = 'closure-only-password';
        $previousIgnoreArguments = ini_get('zend.exception_ignore_args');
        $caughtException = null;
        ini_set('zend.exception_ignore_args', '0');

        try {
            $shop->updateProvisioningTarget(
                slug: 'trace-target',
                databaseDriver: 'mysql',
                databaseName: 'invalid-target-name',
                databaseHost: '192.0.2.95',
                databaseUsername: 'closure-only-user',
                databasePassword: $secret,
            );
        } catch (Throwable $exception) {
            $caughtException = $exception;
        } finally {
            if (is_string($previousIgnoreArguments)) {
                ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
            }
        }

        $valueContainsSecret = function (mixed $value) use (&$valueContainsSecret, $secret): bool {
            if (is_string($value)) {
                return str_contains($value, $secret);
            }

            if ($value instanceof \SensitiveParameterValue) {
                return false;
            }

            if ($value instanceof \Closure) {
                return $valueContainsSecret((new \ReflectionFunction($value))->getStaticVariables());
            }

            if (! is_array($value)) {
                return false;
            }

            foreach ($value as $nestedValue) {
                if ($valueContainsSecret($nestedValue)) {
                    return true;
                }
            }

            return false;
        };

        $this->assertInstanceOf(InvalidArgumentException::class, $caughtException);
        $this->assertFalse($valueContainsSecret($caughtException->getTrace()));
    }

    public function test_invalid_registration_topology_is_not_captured_in_exception_traces(): void
    {
        $secrets = [
            'trace-invalid-database!',
            'trace-host.internal',
            'trace-socket-path',
            'trace-topology-user',
            'trace-topology-password',
        ];
        $previousIgnoreArguments = ini_get('zend.exception_ignore_args');
        $caughtException = null;
        ini_set('zend.exception_ignore_args', '0');

        try {
            Shop::registerForProvisioning(
                name: 'Trace topology shop',
                slug: 'trace-topology-shop',
                databaseDriver: 'mysql',
                databaseName: $secrets[0],
                databaseHost: $secrets[1],
                databasePort: 64321,
                databaseUsername: $secrets[3],
                databasePassword: $secrets[4],
                databaseSocket: $secrets[2],
            );
        } catch (Throwable $exception) {
            $caughtException = $exception;
        } finally {
            if (is_string($previousIgnoreArguments)) {
                ini_set('zend.exception_ignore_args', $previousIgnoreArguments);
            }
        }

        $matchedTopology = [];
        $visitedObjects = new \SplObjectStorage;
        $valueContainsTopology = function (mixed $value) use (
            &$valueContainsTopology,
            &$matchedTopology,
            $secrets,
            $visitedObjects,
        ): bool {
            if ($value instanceof \SensitiveParameterValue) {
                return false;
            }

            if (is_string($value)) {
                foreach ($secrets as $secret) {
                    if (str_contains($value, $secret)) {
                        $matchedTopology[] = $secret;

                        return true;
                    }
                }

                return false;
            }

            if (is_object($value)) {
                if ($visitedObjects->contains($value)) {
                    return false;
                }

                $visitedObjects->attach($value);

                return $valueContainsTopology((array) $value);
            }

            if (! is_array($value)) {
                return false;
            }

            foreach ($value as $nestedValue) {
                if ($valueContainsTopology($nestedValue)) {
                    return true;
                }
            }

            return false;
        };

        $this->assertInstanceOf(InvalidArgumentException::class, $caughtException);
        $this->assertFalse(
            $valueContainsTopology($caughtException->getTrace()),
            'Trace exposed: '.implode(', ', array_unique($matchedTopology)),
        );
    }

    public function test_shop_database_config_omits_missing_connection_overrides(): void
    {
        $shop = Shop::factory()->create();

        $this->assertSame([
            'driver' => 'sqlite',
            'database' => $shop->database_name,
        ], $shop->databaseConfig());
    }

    public function test_generic_database_url_cannot_override_shop_database_targets(): void
    {
        $shopADatabasePath = $this->newTenantDatabasePath('shop-a-');
        $shopBDatabasePath = $this->newTenantDatabasePath('shop-b-');
        $this->writeDatabaseMarker($shopADatabasePath, 'shop-a');
        $this->writeDatabaseMarker($shopBDatabasePath, 'shop-b');
        $tenantTemplate = $this->tenantTemplateWithDatabaseUrl('sqlite:///:memory:');
        $shopA = Shop::factory()->make([
            'database_driver' => 'sqlite',
            'database_name' => $shopADatabasePath,
        ]);
        $shopB = Shop::factory()->make([
            'database_driver' => 'sqlite',
            'database_name' => $shopBDatabasePath,
        ]);

        $this->assertArrayNotHasKey('url', $tenantTemplate);
        $this->assertArrayNotHasKey('url', $shopA->databaseConfig());
        $this->assertSame('shop-a', $this->readDatabaseMarker($tenantTemplate, $shopA));
        $this->assertSame('shop-b', $this->readDatabaseMarker($tenantTemplate, $shopB));
    }

    public function test_generic_database_url_cannot_override_central_database_target(): void
    {
        $centralDatabasePath = $this->newTemporaryDatabasePath('central-config-');
        $this->writeDatabaseMarker($centralDatabasePath, 'central');

        $result = $this->centralConnectionWithGenericDatabaseUrl(
            centralDatabasePath: $centralDatabasePath,
            genericDatabaseUrl: 'sqlite:///:memory:',
        );

        $this->assertNull($result['configured_url']);
        $this->assertSame($centralDatabasePath, $result['database']);
        $this->assertSame('central', $result['marker']);
    }

    public function test_shop_database_config_returns_decrypted_connection_overrides(): void
    {
        $this->resolveDatabaseHosts([
            'db.internal' => ['192.0.2.12'],
        ]);

        $shop = Shop::factory()->create([
            'database_driver' => 'mysql',
            'database_name' => 'shop_alpha',
            'database_host' => 'db.internal',
            'database_port' => 3307,
            'database_username' => 'shop-user',
            'database_password' => 'secret-password',
        ]);

        $storedShop = DB::connection('central')->table('shops')->where('id', $shop->getKey())->first();

        $this->assertNotNull($storedShop);
        $this->assertNotSame('db.internal', $storedShop->database_host);
        $this->assertNotSame('3307', $storedShop->database_port);
        $this->assertNotSame('shop-user', $storedShop->database_username);
        $this->assertNotSame('secret-password', $storedShop->database_password);
        $this->assertSame([
            'driver' => 'mysql',
            'database' => 'shop_alpha',
            'host' => 'db.internal',
            'port' => 3307,
            'username' => 'shop-user',
            'password' => 'secret-password',
        ], $shop->databaseConfig());
    }

    public function test_ordinary_shop_saves_do_not_reencrypt_unchanged_connection_overrides(): void
    {
        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_ciphertext',
            databaseHost: '192.0.2.70',
            databasePort: 3307,
            databaseUsername: 'cipher-user',
            databasePassword: 'cipher-password',
        );
        $encrypter = app('encrypter');
        $cipher = config('app.cipher');

        if (! $encrypter instanceof Encrypter || ! is_string($cipher)) {
            throw new RuntimeException('The application encryption configuration is unavailable.');
        }

        $previousKey = Encrypter::generateKey($cipher);
        $previousEncrypter = new Encrypter($previousKey, $cipher);
        DB::connection('central')->table('shops')->where('id', $shop->getKey())->update([
            'database_host' => $previousEncrypter->encrypt(json_encode('192.0.2.70', JSON_THROW_ON_ERROR), false),
            'database_port' => $previousEncrypter->encrypt('3307', false),
            'database_username' => $previousEncrypter->encrypt('cipher-user', false),
            'database_password' => $previousEncrypter->encrypt('cipher-password', false),
        ]);
        $storedBeforeSave = (array) DB::connection('central')
            ->table('shops')
            ->where('id', $shop->getKey())
            ->first();
        $previousKeys = $encrypter->getPreviousKeys();
        $encrypter->previousKeys([$previousKey, ...$previousKeys]);

        try {
            $shop = $shop->fresh();
            $shop->name = 'Renamed without credential rotation';
            $shop->save();
        } finally {
            $encrypter->previousKeys($previousKeys);
        }

        $storedAfterSave = (array) DB::connection('central')
            ->table('shops')
            ->where('id', $shop->getKey())
            ->first();

        foreach (['database_host', 'database_port', 'database_socket', 'database_username', 'database_password'] as $field) {
            $this->assertSame($storedBeforeSave[$field], $storedAfterSave[$field]);
        }
        $this->assertSame('Renamed without credential rotation', $storedAfterSave['name']);
    }

    public function test_reapplying_the_same_provisioning_target_preserves_encrypted_values(): void
    {
        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_same_target',
            databaseHost: '192.0.2.71',
            databasePort: 3307,
            databaseUsername: 'same-user',
            databasePassword: 'same-password',
        );
        $shop->markProvisioningFailed('Retry with the same target.');
        $storedBeforeUpdate = (array) DB::connection('central')
            ->table('shops')
            ->where('id', $shop->getKey())
            ->first();
        $encrypter = app('encrypter');

        if (! $encrypter instanceof Encrypter) {
            throw new RuntimeException('The application encrypter is unavailable.');
        }

        $previousKeys = $encrypter->getPreviousKeys();
        $encrypter->previousKeys([str_repeat('p', 32)]);

        try {
            $shop->updateProvisioningTarget(
                slug: 'alpha-workshop',
                databaseDriver: 'mysql',
                databaseName: 'tenant_same_target',
                databaseHost: '192.0.2.71',
                databasePort: 3307,
                databaseUsername: 'same-user',
                databasePassword: 'same-password',
            );
        } finally {
            $encrypter->previousKeys($previousKeys);
        }

        $storedAfterUpdate = (array) DB::connection('central')
            ->table('shops')
            ->where('id', $shop->getKey())
            ->first();

        foreach (['database_host', 'database_port', 'database_socket', 'database_username', 'database_password'] as $field) {
            $this->assertSame($storedBeforeUpdate[$field], $storedAfterUpdate[$field]);
        }
    }

    public function test_platform_guard_uses_the_platform_user_provider(): void
    {
        $provider = Auth::guard('platform')->getProvider();

        $this->assertSame(PlatformUser::class, $provider->getModel());
    }

    public function test_platform_provider_rejects_inactive_users(): void
    {
        $activeUser = PlatformUser::factory()->create(['email' => 'active-platform@example.com']);
        $inactiveUser = PlatformUser::factory()->create(['email' => 'inactive-platform@example.com']);
        $inactiveUser->deactivate();
        $guard = Auth::guard('platform');

        $this->assertTrue($guard->validate([
            'email' => $activeUser->email,
            'password' => 'password',
        ]));
        $this->assertFalse($guard->validate([
            'email' => $inactiveUser->email,
            'password' => 'password',
        ]));
        $this->assertNull($guard->getProvider()->retrieveById($inactiveUser->getKey()));
    }

    public function test_platform_privilege_and_login_audit_fields_are_not_mass_assignable(): void
    {
        $platformUser = PlatformUser::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
            'last_login_at' => null,
        ]);

        $platformUser->fill([
            'name' => 'Renamed Platform User',
            'role' => 'untrusted_role',
            'is_active' => false,
            'last_login_at' => now(),
        ]);

        $this->assertSame('Renamed Platform User', $platformUser->name);
        $this->assertSame('super_admin', $platformUser->role);
        $this->assertTrue($platformUser->is_active);
        $this->assertNull($platformUser->last_login_at);
    }

    public function test_platform_account_state_and_login_audit_use_controlled_methods(): void
    {
        PlatformUser::factory()->create();
        $platformUser = PlatformUser::factory()->create();

        $platformUser->deactivate();
        $this->assertFalse($platformUser->fresh()->is_active);

        $platformUser->activate();
        $this->assertTrue($platformUser->fresh()->is_active);

        $this->travelTo('2026-09-02 10:30:00');
        $platformUser->recordSuccessfulLogin();

        $this->assertSame('2026-09-02 10:30:00', $platformUser->fresh()->last_login_at?->format('Y-m-d H:i:s'));
    }

    public function test_stale_platform_users_cannot_deactivate_the_final_active_super_admin(): void
    {
        $firstPlatformUser = PlatformUser::factory()->create();
        $secondPlatformUser = PlatformUser::factory()->create();
        $staleSecondPlatformUser = $secondPlatformUser->fresh();

        $firstPlatformUser->deactivate();

        try {
            $staleSecondPlatformUser->deactivate();
            $this->fail('A stale platform user deactivated the final active super administrator.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'The final active super administrator cannot be deactivated.',
                $exception->getMessage(),
            );
        }

        $this->assertFalse($firstPlatformUser->fresh()->is_active);
        $this->assertTrue($secondPlatformUser->fresh()->is_active);
        $this->assertSame(1, PlatformUser::query()
            ->where('role', 'super_admin')
            ->where('is_active', true)
            ->count());
    }

    public function test_shop_lifecycle_and_database_fields_are_not_mass_assignable(): void
    {
        $databasePath = $this->newTenantDatabasePath('tenant-assignment-');
        $shop = $this->registerShop(databaseName: $databasePath);
        $databaseTargetFingerprint = $shop->database_target_fingerprint;
        $databaseTargetLocatorFingerprint = $shop->database_target_locator_fingerprint;

        $shop->fill([
            'name' => 'Allowed Shop Name',
            'slug' => 'attacker-slug',
            'status' => ShopStatus::Active,
            'database_driver' => 'mysql',
            'database_name' => 'attacker_database',
            'database_host' => 'attacker.internal',
            'database_socket' => $this->newTenantDatabasePath('attacker-socket-', create: false),
            'database_target_fingerprint' => str_repeat('0', 64),
            'database_target_locator_fingerprint' => str_repeat('1', 64),
            'provisioned_at' => now(),
            'provisioning_failure_message' => 'Forged failure',
        ]);

        $this->assertSame('Allowed Shop Name', $shop->name);
        $this->assertSame('alpha-workshop', $shop->slug);
        $this->assertSame(ShopStatus::Provisioning, $shop->status);
        $this->assertSame('sqlite', $shop->database_driver);
        $this->assertSame($databasePath, $shop->database_name);
        $this->assertNull($shop->database_host);
        $this->assertNull($shop->database_socket);
        $this->assertSame($databaseTargetFingerprint, $shop->database_target_fingerprint);
        $this->assertSame($databaseTargetLocatorFingerprint, $shop->database_target_locator_fingerprint);
        $this->assertNull($shop->provisioned_at);
        $this->assertNull($shop->provisioning_failure_message);
    }

    public function test_shop_registration_rejects_unsupported_database_drivers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported shop database driver [pgsql].');

        $this->registerShop(databaseDriver: 'pgsql');
    }

    #[DataProvider('unsafeMySqlDatabaseNames')]
    public function test_shop_registration_rejects_unsafe_mysql_database_names(string $databaseName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MySQL database names may contain only 1-64 ASCII letters, numbers, or underscores.');

        $this->registerShop(databaseDriver: 'mysql', databaseName: $databaseName);
    }

    public function test_shop_registration_canonicalizes_mysql_database_names(): void
    {
        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: ' Tenant_Alpha_01 ',
        );

        $this->assertSame('Tenant_Alpha_01', $shop->database_name);
    }

    public function test_shop_registration_rejects_duplicate_normalized_mysql_database_targets(): void
    {
        $this->resolveDatabaseHosts([
            'db.example.com' => ['192.0.2.10'],
        ]);

        $firstShop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: ' Tenant_Alpha ',
            slug: 'alpha-workshop',
            databaseHost: 'DB.EXAMPLE.COM.',
            databaseUsername: 'first-user',
            databasePassword: 'first-password',
        );

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'Tenant_Alpha',
                slug: 'beta-workshop',
                databaseHost: 'db.example.com',
                databasePort: 3306,
                databaseUsername: 'second-user',
                databasePassword: 'second-password',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
        $this->assertSame('Tenant_Alpha', $firstShop->database_name);
    }

    public function test_mysql_database_identity_preserves_schema_case_but_rejects_case_only_aliases(): void
    {
        $firstShop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'Tenant_Case',
            databaseHost: '192.0.2.11',
            slug: 'alpha-workshop',
        );

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'tenant_case',
                databaseHost: '192.0.2.11',
                slug: 'beta-workshop',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame('Tenant_Case', $firstShop->database_name);
        $this->assertSame(1, Shop::query()->count());
    }

    public function test_shop_registration_rejects_mysql_loopback_host_aliases(): void
    {
        $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_loopback',
            databaseHost: 'localhost',
        );

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'tenant_loopback',
                slug: 'beta-workshop',
                databaseHost: '[0:0:0:0:0:0:0:1]',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
    }

    public function test_shop_registration_rejects_ipv4_mapped_ipv6_host_aliases(): void
    {
        $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_mapped_ipv6',
            databaseHost: '192.0.2.90',
        );

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'tenant_mapped_ipv6',
                slug: 'beta-workshop',
                databaseHost: '[::ffff:192.0.2.90]',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
    }

    public function test_shop_registration_rejects_equivalent_ipv6_text_forms(): void
    {
        $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_ipv6_text',
            databaseHost: '2001:db8::44',
        );

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'tenant_ipv6_text',
                slug: 'beta-workshop',
                databaseHost: '[2001:0db8:0:0:0:0:0:44]',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
    }

    public function test_shop_registration_rejects_mysql_dns_aliases(): void
    {
        $this->resolveDatabaseHosts([
            'db-primary.example.test' => ['192.0.2.40', '2001:db8::40'],
            'db-alias.example.test' => ['2001:0db8:0:0:0:0:0:40', '192.0.2.40'],
        ]);

        $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_dns_alias',
            databaseHost: 'db-primary.example.test',
        );

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'tenant_dns_alias',
                slug: 'beta-workshop',
                databaseHost: 'db-alias.example.test',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
    }

    public function test_shop_registration_rejects_partially_overlapping_mysql_endpoint_sets(): void
    {
        $this->resolveDatabaseHosts([
            'db-primary.example.test' => ['192.0.2.50', '192.0.2.51'],
            'db-overlap.example.test' => ['192.0.2.51', '192.0.2.52'],
        ]);

        $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_endpoint_overlap',
            databaseHost: 'db-primary.example.test',
        );

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'tenant_endpoint_overlap',
                slug: 'beta-workshop',
                databaseHost: 'db-overlap.example.test',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
    }

    public function test_mysql_multi_host_arrays_are_preserved_and_claim_every_resolved_endpoint(): void
    {
        $this->resolveDatabaseHosts([
            'db-a.example.test' => ['192.0.2.61', '2001:db8::61'],
            'db-b.example.test' => ['192.0.2.62'],
            'db-overlap.example.test' => ['2001:0db8:0:0:0:0:0:61'],
        ]);

        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'Tenant_Multi_Host',
            databaseHost: ['DB-A.EXAMPLE.TEST.', 'db-b.example.test'],
        );

        $this->assertSame(
            ['db-a.example.test', 'db-b.example.test'],
            $shop->databaseConfig()['host'],
        );
        $this->assertSame(
            3,
            DB::connection('central')
                ->table('shop_database_target_claims')
                ->where('shop_id', $shop->getKey())
                ->count(),
        );
        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'tenant_multi_host',
                slug: 'beta-workshop',
                databaseHost: 'db-overlap.example.test',
            ),
            'The tenant database target is already assigned to another shop.',
        );
    }

    public function test_shop_registration_rejects_a_dns_alias_of_a_literal_ip_target(): void
    {
        $this->resolveDatabaseHosts([
            'db-alias.example.test' => ['192.0.2.42'],
        ]);

        $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_ip_alias',
            databaseHost: '192.0.2.42',
        );

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'tenant_ip_alias',
                slug: 'beta-workshop',
                databaseHost: 'db-alias.example.test',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
    }

    public function test_unresolvable_mysql_hosts_fail_closed(): void
    {
        $this->resolveDatabaseHosts([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MySQL tenant database hosts must resolve to a stable IP address.');

        $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_unresolvable',
            databaseHost: 'unresolvable.example.test',
        );
    }

    public function test_non_ip_mysql_hosts_cannot_use_ip_literal_brackets(): void
    {
        $this->resolveDatabaseHosts([
            'db.example.test' => ['192.0.2.43'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'MySQL tenant database hosts must be canonical host names or IP addresses.',
        );

        $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_bracketed_hostname',
            databaseHost: '[db.example.test]',
        );
    }

    public function test_central_mysql_dns_aliases_use_the_same_physical_identity(): void
    {
        $this->resolveDatabaseHosts([
            'central-primary.example.test' => ['192.0.2.41', '2001:db8::41'],
            'central-alias.example.test' => ['2001:0db8:0:0:0:0:0:41', '192.0.2.41'],
        ]);
        config()->set('database.connections.central', [
            'driver' => 'mysql',
            'host' => 'central-primary.example.test',
            'port' => 3306,
            'database' => 'Central_Dns',
            'unix_socket' => '',
        ]);

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'Central_Dns',
                databaseHost: 'central-alias.example.test',
            ),
            'The central platform database cannot be assigned to a shop.',
        );
    }

    public function test_central_mysql_endpoint_claims_reject_partial_dns_overlap(): void
    {
        $this->resolveDatabaseHosts([
            'central-primary.example.test' => ['192.0.2.71', '192.0.2.72'],
            'tenant-overlap.example.test' => ['192.0.2.72', '192.0.2.73'],
        ]);
        config()->set('database.connections.central', [
            'driver' => 'mysql',
            'host' => 'central-primary.example.test',
            'port' => 3306,
            'database' => 'Central_Endpoint_Overlap',
            'unix_socket' => '',
        ]);

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'central_endpoint_overlap',
                databaseHost: 'tenant-overlap.example.test',
            ),
            'The central platform database cannot be assigned to a shop.',
        );
    }

    public function test_mysql_template_defaults_affect_identity_without_becoming_shop_overrides(): void
    {
        config()->set('database.connections.tenant.host', '192.0.2.60');
        config()->set('database.connections.tenant.port', 3310);
        config()->set('database.connections.tenant.unix_socket', '');

        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_defaults',
        );
        $storedShop = DB::connection('central')->table('shops')->where('id', $shop->getKey())->first();

        $this->assertNotNull($storedShop);
        $this->assertNull($storedShop->database_host);
        $this->assertNull($storedShop->database_port);
        $this->assertNull($storedShop->database_socket);
        $this->assertSame([
            'driver' => 'mysql',
            'database' => 'tenant_defaults',
        ], $shop->databaseConfig());
    }

    public function test_mysql_socket_replaces_irrelevant_host_and_port_in_the_normalized_target(): void
    {
        $socketPath = $this->newTenantDatabasePath('mysql-socket-', create: false);
        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_socket',
            databaseHost: 'ignored.example.com',
            databasePort: 3307,
            databaseSocket: $socketPath,
        );

        $this->assertSame([
            'driver' => 'mysql',
            'database' => 'tenant_socket',
            'unix_socket' => $socketPath,
        ], $shop->databaseConfig());

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'tenant_socket',
                slug: 'beta-workshop',
                databaseHost: 'different-ignored.example.com',
                databasePort: 4407,
                databaseSocket: $socketPath,
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
    }

    public function test_central_mysql_socket_aliases_use_the_same_canonical_realpath(): void
    {
        $socketPath = $this->newTenantDatabasePath('central-socket-');
        $socketAlias = dirname($socketPath)
            .DIRECTORY_SEPARATOR.'.'.DIRECTORY_SEPARATOR.basename($socketPath);
        config()->set('database.connections.central', [
            'driver' => 'mysql',
            'host' => 'ignored.example.test',
            'port' => 3306,
            'database' => 'Central_Socket',
            'unix_socket' => $socketPath,
        ]);

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'Central_Socket',
                databaseHost: 'also-ignored.example.test',
                databaseSocket: $socketAlias,
            ),
            'The central platform database cannot be assigned to a shop.',
        );
    }

    public function test_mysql_socket_identity_survives_socket_inode_replacement(): void
    {
        $socketPath = $this->newTenantDatabasePath('replaceable-mysql-socket-');
        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_replaceable_socket',
            databaseSocket: $socketPath,
        );
        $registeredFingerprint = $shop->database_target_fingerprint;

        File::delete($socketPath);
        File::put($socketPath, 'replacement socket inode');

        $this->assertSame([
            'driver' => 'mysql',
            'database' => 'tenant_replaceable_socket',
            'unix_socket' => $socketPath,
        ], $shop->databaseConfig());
        $this->assertSame($registeredFingerprint, $shop->fresh()->database_target_fingerprint);
    }

    public function test_shop_registration_rejects_the_central_mysql_database_target(): void
    {
        $this->resolveDatabaseHosts([
            'central-db.example.com' => ['192.0.2.20'],
        ]);
        config()->set('database.connections.central', [
            'driver' => 'mysql',
            'host' => 'central-db.example.com',
            'port' => 3306,
            'database' => 'platform_control',
            'username' => 'central-user',
            'password' => 'central-password',
            'unix_socket' => '',
        ]);

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'platform_control',
                databaseHost: 'CENTRAL-DB.EXAMPLE.COM.',
                databasePort: 3306,
            ),
            'The central platform database cannot be assigned to a shop.',
        );

        $this->assertSame(0, Shop::query()->count());
    }

    public function test_shop_registration_treats_the_central_mariadb_driver_as_a_mysql_target(): void
    {
        $this->resolveDatabaseHosts([
            'central-db.example.com' => ['192.0.2.20'],
        ]);
        config()->set('database.connections.central', [
            'driver' => 'mariadb',
            'host' => 'central-db.example.com',
            'port' => 3306,
            'database' => 'platform_control',
            'username' => 'central-user',
            'password' => 'central-password',
            'unix_socket' => '',
        ]);

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseDriver: 'mysql',
                databaseName: 'platform_control',
                databaseHost: 'central-db.example.com',
                databasePort: 3306,
            ),
            'The central platform database cannot be assigned to a shop.',
        );
    }

    #[DataProvider('unsafeSqliteDatabasePaths')]
    public function test_shop_registration_rejects_unsafe_sqlite_database_paths(string $databaseName): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SQLite tenant databases require a canonical absolute file path.');

        $this->registerShop(databaseName: $databaseName);
    }

    public function test_shop_registration_canonicalizes_sqlite_database_paths(): void
    {
        $databasePath = $this->newTenantDatabasePath('tenant-canonical-');
        $pathWithCurrentDirectorySegment = dirname($databasePath)
            .DIRECTORY_SEPARATOR.'.'.DIRECTORY_SEPARATOR.basename($databasePath);

        $shop = $this->registerShop(databaseName: $pathWithCurrentDirectorySegment);

        $this->assertSame($databasePath, $shop->database_name);
    }

    public function test_shop_registration_rejects_duplicate_sqlite_targets_before_the_file_exists(): void
    {
        $databasePath = $this->newTenantDatabasePath('pending-', create: false);
        $pathWithCurrentDirectorySegment = dirname($databasePath)
            .DIRECTORY_SEPARATOR.'.'.DIRECTORY_SEPARATOR.basename($databasePath);
        $firstShop = $this->registerShop(
            databaseName: $pathWithCurrentDirectorySegment,
            slug: 'alpha-workshop',
        );

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseName: $databasePath,
                slug: 'beta-workshop',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
        $this->assertSame($databasePath, $firstShop->database_name);
    }

    public function test_shop_registration_rejects_the_central_sqlite_database_target(): void
    {
        $centralDatabasePath = DB::connection('central')->getDatabaseName();

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(databaseName: $centralDatabasePath),
            'The central platform database cannot be assigned to a shop.',
        );

        $this->assertSame(0, Shop::query()->count());
    }

    public function test_relative_central_sqlite_paths_resolve_from_the_application_base_path(): void
    {
        $centralDatabasePath = base_path(
            'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR
            .'central-relative-'.Str::uuid().'.sqlite',
        );
        File::ensureDirectoryExists(dirname($centralDatabasePath));

        if (File::put($centralDatabasePath, '') === false) {
            throw new RuntimeException('Unable to create a relative central SQLite database.');
        }

        $this->temporaryDatabasePaths[] = $centralDatabasePath;
        $relativeCentralPath = Str::after($centralDatabasePath, base_path().DIRECTORY_SEPARATOR);
        config()->set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => $relativeCentralPath,
        ]);

        $distinctShop = $this->registerShop(
            databaseName: $this->newTenantDatabasePath('distinct-relative-central-'),
            slug: 'alpha-workshop',
        );

        $this->assertModelExists($distinctShop);
        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseName: $centralDatabasePath,
                slug: 'beta-workshop',
            ),
            'The central platform database cannot be assigned to a shop.',
        );
        $this->assertSame(1, Shop::query()->count());
    }

    public function test_shop_registration_rejects_sqlite_targets_outside_the_configured_root(): void
    {
        $outsideDatabasePath = $this->newTemporaryDatabasePath('outside-tenant-root-');

        try {
            $this->registerShop(databaseName: $outsideDatabasePath);
            $this->fail('A shop accepted a SQLite target outside the configured tenant root.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'SQLite tenant databases must be inside the configured tenant database root.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, Shop::query()->count());
    }

    public function test_shop_registration_rejects_a_resolvable_sqlite_symlink_alias(): void
    {
        $databasePath = $this->newTenantDatabasePath('symlink-target-');
        $aliasPath = $this->tenantDatabaseRoot.DIRECTORY_SEPARATOR.'symlink-alias.sqlite';

        if (! @symlink($databasePath, $aliasPath)) {
            $this->markTestSkipped('File symlinks are not available on this platform.');
        }

        $this->temporaryDatabasePaths[] = $aliasPath;
        $this->registerShop(databaseName: $databasePath, slug: 'alpha-workshop');

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseName: $aliasPath,
                slug: 'beta-workshop',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
    }

    public function test_shop_registration_rejects_a_sqlite_hard_link_alias(): void
    {
        $databasePath = $this->newTenantDatabasePath('hard-link-target-');
        $aliasPath = $this->tenantDatabaseRoot.DIRECTORY_SEPARATOR.'hard-link-alias.sqlite';

        if (! function_exists('link') || ! @link($databasePath, $aliasPath)) {
            $this->markTestSkipped('File hard links are not available on this filesystem.');
        }

        $this->temporaryDatabasePaths[] = $aliasPath;
        $databaseIdentity = $this->filesystemIdentity($databasePath);
        $aliasIdentity = $this->filesystemIdentity($aliasPath);

        if ($databaseIdentity === null || $databaseIdentity !== $aliasIdentity) {
            $this->markTestSkipped('Stable filesystem identity is not available on this platform.');
        }

        $this->registerShop(databaseName: $databasePath, slug: 'alpha-workshop');

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseName: $aliasPath,
                slug: 'beta-workshop',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
    }

    public function test_shop_registration_rejects_a_hard_link_to_the_central_sqlite_file(): void
    {
        $centralDatabasePath = DB::connection('central')->getDatabaseName();
        $aliasPath = $this->tenantDatabaseRoot.DIRECTORY_SEPARATOR.'central-hard-link.sqlite';

        if (! function_exists('link') || ! @link($centralDatabasePath, $aliasPath)) {
            $this->markTestSkipped('File hard links are not available on this filesystem.');
        }

        $this->temporaryDatabasePaths[] = $aliasPath;

        if ($this->filesystemIdentity($centralDatabasePath) !== $this->filesystemIdentity($aliasPath)) {
            $this->markTestSkipped('Stable filesystem identity is not available on this platform.');
        }

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(databaseName: $aliasPath),
            'The central platform database cannot be assigned to a shop.',
        );

        $this->assertSame(0, Shop::query()->count());
    }

    public function test_provisioning_transition_materializes_a_newly_created_sqlite_identity(): void
    {
        $databasePath = $this->newTenantDatabasePath('pending-revalidation-', create: false);
        $shop = $this->registerShop(databaseName: $databasePath);
        $pathFingerprint = $shop->database_target_fingerprint;
        $createdIdentity = $this->createSqliteDatabaseIdentitySnapshot($databasePath);

        $aliasPath = $this->tenantDatabaseRoot.DIRECTORY_SEPARATOR.'revalidated-hard-link.sqlite';

        if (! function_exists('link') || ! @link($databasePath, $aliasPath)) {
            $this->markTestSkipped('File hard links are not available on this filesystem.');
        }

        $this->temporaryDatabasePaths[] = $aliasPath;

        if ($this->filesystemIdentity($databasePath) !== $this->filesystemIdentity($aliasPath)) {
            $this->markTestSkipped('Stable filesystem identity is not available on this platform.');
        }

        $shop->materializeSqliteDatabaseIdentityAfterCreation($createdIdentity);

        $this->assertNotSame($pathFingerprint, $shop->database_target_fingerprint);
        $this->assertSame([
            'driver' => 'sqlite',
            'database' => $databasePath,
        ], $shop->databaseConfig());

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseName: $aliasPath,
                slug: 'beta-workshop',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
    }

    public function test_database_config_rejects_a_replaced_sqlite_file_without_trusting_it(): void
    {
        $databasePath = $this->newTenantDatabasePath('replace-target-');
        $shop = $this->registerShop(databaseName: $databasePath);
        $shop->markActive();
        $registeredFingerprint = $shop->database_target_fingerprint;

        File::delete($databasePath);

        if (File::put($databasePath, 'replacement') === false) {
            throw new RuntimeException('Unable to replace a temporary tenant SQLite database.');
        }

        $this->assertTenantTargetConflict(
            fn (): array => $shop->databaseConfig(),
            'The tenant database target identity changed after registration.',
        );

        $this->assertSame(
            $registeredFingerprint,
            DB::connection('central')->table('shops')->where('id', $shop->getKey())
                ->value('database_target_fingerprint'),
        );
    }

    public function test_database_config_requires_the_registered_sqlite_path_and_file_identity(): void
    {
        $databasePath = $this->newTenantDatabasePath('strict-path-target-');
        $aliasPath = $this->tenantDatabaseRoot.DIRECTORY_SEPARATOR.'strict-path-hard-link.sqlite';

        if (! function_exists('link') || ! @link($databasePath, $aliasPath)) {
            $this->markTestSkipped('File hard links are not available on this filesystem.');
        }

        $this->temporaryDatabasePaths[] = $aliasPath;

        if ($this->filesystemIdentity($databasePath) !== $this->filesystemIdentity($aliasPath)) {
            $this->markTestSkipped('Stable filesystem identity is not available on this platform.');
        }

        $shop = $this->registerShop(databaseName: $databasePath);
        $registeredLocatorFingerprint = $shop->database_target_locator_fingerprint;
        DB::connection('central')->table('shops')->where('id', $shop->getKey())->update([
            'database_name' => $aliasPath,
        ]);

        $this->assertTenantTargetConflict(
            fn (): array => $shop->databaseConfig(),
            'The tenant database target identity changed after registration.',
        );

        $storedShop = DB::connection('central')->table('shops')->where('id', $shop->getKey())->first();

        $this->assertNotNull($storedShop);
        $this->assertSame($aliasPath, $storedShop->database_name);
        $this->assertSame($registeredLocatorFingerprint, $storedShop->database_target_locator_fingerprint);
    }

    public function test_replacing_a_sqlite_file_does_not_release_its_registered_path(): void
    {
        $databasePath = $this->newTenantDatabasePath('owned-path-');
        $this->registerShop(databaseName: $databasePath, slug: 'alpha-workshop');

        File::delete($databasePath);

        if (File::put($databasePath, 'new-file-at-owned-path') === false) {
            throw new RuntimeException('Unable to replace a temporary tenant SQLite database.');
        }

        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseName: $databasePath,
                slug: 'beta-workshop',
            ),
            'The tenant database target is already assigned to another shop.',
        );

        $this->assertSame(1, Shop::query()->count());
    }

    public function test_database_config_rejects_dns_retargeting_without_trusting_it(): void
    {
        $resolver = new class implements DatabaseHostResolver
        {
            /** @var list<string> */
            public array $addresses = ['192.0.2.80'];

            public function resolve(string $host): array
            {
                return $host === 'mutable-db.example.test' ? $this->addresses : [];
            }
        };
        app()->instance(DatabaseHostResolver::class, $resolver);
        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_dns_retarget',
            databaseHost: 'mutable-db.example.test',
        );
        $shop->markActive();
        $shop->suspend();
        $registeredFingerprint = $shop->database_target_fingerprint;

        $resolver->addresses = ['192.0.2.81'];

        $this->assertTenantTargetConflict(
            fn (): array => $shop->databaseConfig(),
            'The tenant database target identity changed after registration.',
        );
        $this->assertSame(
            $registeredFingerprint,
            DB::connection('central')->table('shops')->where('id', $shop->getKey())
                ->value('database_target_fingerprint'),
        );
    }

    public function test_revalidation_resolves_mysql_endpoints_without_a_central_transaction(): void
    {
        $resolver = new class implements DatabaseHostResolver
        {
            /** @var list<int> */
            public array $centralTransactionLevels = [];

            public function resolve(string $host): array
            {
                $this->centralTransactionLevels[] = DB::connection('central')->transactionLevel();

                return $host === 'validation.example.test' ? ['192.0.2.91'] : [];
            }
        };
        app()->instance(DatabaseHostResolver::class, $resolver);
        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_read_snapshot',
            databaseHost: 'validation.example.test',
        );
        $resolver->centralTransactionLevels = [];

        $validatedTarget = $shop->revalidateDatabaseTarget();

        $this->assertSame([0], $resolver->centralTransactionLevels);
        $this->assertSame([
            [
                'host' => 'validation.example.test',
                'address' => '192.0.2.91',
                'port' => 3306,
            ],
        ], $validatedTarget->mysqlEndpoints);
    }

    public function test_soft_deleted_shops_cannot_revalidate_or_return_database_configuration(): void
    {
        $shop = $this->registerShop();
        $claimCount = DB::connection('central')
            ->table('shop_database_target_claims')
            ->where('shop_id', $shop->getKey())
            ->count();
        $shop->delete();

        foreach (['revalidateDatabaseTarget', 'databaseConfig'] as $method) {
            try {
                $shop->{$method}();
                $this->fail("A soft-deleted shop returned from {$method}().");
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Deleted shops do not have usable database targets.',
                    $exception->getMessage(),
                );
            }
        }

        $this->assertSame(
            $claimCount,
            DB::connection('central')
                ->table('shop_database_target_claims')
                ->where('shop_id', $shop->getKey())
                ->count(),
        );
        $this->assertTenantTargetConflict(
            fn (): Shop => $this->registerShop(
                databaseName: $shop->database_name,
                slug: 'replacement-for-deleted-shop',
            ),
            'The tenant database target is already assigned to another shop.',
        );
    }

    public function test_sqlite_identity_materialization_is_provisioning_only(): void
    {
        $databasePath = $this->newTenantDatabasePath('materialization-state-', create: false);
        $shop = $this->registerShop(databaseName: $databasePath);
        $createdIdentity = $this->createSqliteDatabaseIdentitySnapshot($databasePath);

        $shop->materializeSqliteDatabaseIdentityAfterCreation($createdIdentity);
        $shop->markActive();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'SQLite database identity can only be materialized while a shop is provisioning.',
        );

        $shop->materializeSqliteDatabaseIdentityAfterCreation($createdIdentity);
    }

    public function test_sqlite_identity_materialization_happens_only_once_per_reserved_path(): void
    {
        $databasePath = $this->newTenantDatabasePath('one-time-materialization-', create: false);
        $shop = $this->registerShop(databaseName: $databasePath);
        $createdIdentity = $this->createSqliteDatabaseIdentitySnapshot($databasePath);

        $shop->materializeSqliteDatabaseIdentityAfterCreation($createdIdentity);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The shop does not have a newly created SQLite database identity to materialize.',
        );

        $shop->materializeSqliteDatabaseIdentityAfterCreation($createdIdentity);
    }

    public function test_sqlite_identity_materialization_rejects_a_path_replaced_after_exclusive_creation(): void
    {
        $databasePath = $this->newTenantDatabasePath('materialization-replacement-', create: false);
        $shop = $this->registerShop(databaseName: $databasePath);
        $createdIdentity = $this->createSqliteDatabaseIdentitySnapshot($databasePath);

        File::delete($databasePath);
        File::put($databasePath, 'replacement after exclusive creation');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'The SQLite database path no longer matches the exclusive creation snapshot.',
        );

        $shop->materializeSqliteDatabaseIdentityAfterCreation($createdIdentity);
    }

    public function test_soft_deleted_shops_cannot_materialize_sqlite_identity(): void
    {
        $databasePath = $this->newTenantDatabasePath('deleted-materialization-', create: false);
        $shop = $this->registerShop(databaseName: $databasePath);
        $createdIdentity = $this->createSqliteDatabaseIdentitySnapshot($databasePath);
        $shop->delete();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Deleted shops do not have usable database targets.');

        $shop->materializeSqliteDatabaseIdentityAfterCreation($createdIdentity);
    }

    public function test_shop_database_target_fingerprint_is_persisted_and_hidden(): void
    {
        $this->assertTrue(Schema::connection('central')->hasColumn('shops', 'database_target_fingerprint'));
        $this->assertTrue(Schema::connection('central')->hasColumn('shops', 'database_target_locator_fingerprint'));
        $this->resolveDatabaseHosts([
            'db.example.com' => ['192.0.2.30'],
        ]);

        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_fingerprint',
            databaseHost: 'db.example.com',
            databaseUsername: 'credential-user',
            databasePassword: 'credential-password',
        );
        $fingerprint = DB::connection('central')
            ->table('shops')
            ->where('id', $shop->getKey())
            ->value('database_target_fingerprint');
        $locatorFingerprint = DB::connection('central')
            ->table('shops')
            ->where('id', $shop->getKey())
            ->value('database_target_locator_fingerprint');

        $this->assertIsString($fingerprint);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $fingerprint);
        $this->assertNull($locatorFingerprint);
        $this->assertArrayNotHasKey('database_target_fingerprint', $shop->toArray());
        $this->assertArrayNotHasKey('database_target_locator_fingerprint', $shop->toArray());
    }

    public function test_database_unique_race_is_translated_to_a_tenant_target_conflict(): void
    {
        $this->resolveDatabaseHosts([
            'race-db.example.com' => ['192.0.2.50'],
        ]);
        $eventName = 'eloquent.creating: '.Shop::class;
        Event::listen($eventName, static function (Shop $shop): void {
            DB::connection('central')->table('shops')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'Competing Shop',
                'slug' => 'competing-shop',
                'status' => ShopStatus::Provisioning->value,
                'database_driver' => $shop->database_driver,
                'database_name' => $shop->database_name,
                'database_target_fingerprint' => $shop->database_target_fingerprint,
                'timezone' => 'Asia/Karachi',
                'currency' => 'PKR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $caughtException = $this->assertTenantTargetConflict(
                fn (): Shop => $this->registerShop(
                    databaseDriver: 'mysql',
                    databaseName: 'tenant_race',
                    databaseHost: 'race-db.example.com',
                ),
                'The tenant database target is already assigned to another shop.',
            );
        } finally {
            Event::forget($eventName);
        }

        $this->assertNull($caughtException->getPrevious());
        $this->assertSame(0, Shop::query()->count());
    }

    public function test_endpoint_claim_unique_race_removes_the_unassigned_shop_and_translates_the_conflict(): void
    {
        $this->resolveDatabaseHosts([
            'claim-race.example.test' => ['192.0.2.96'],
        ]);
        $tenantConfiguration = config('database.connections.tenant');
        $centralConfiguration = config('database.connections.central');

        if (! is_array($tenantConfiguration) || ! is_array($centralConfiguration)) {
            throw new RuntimeException('Database connection configuration is unavailable.');
        }

        $target = NormalizedDatabaseTarget::forTenant(
            target: new DatabaseTargetConfiguration(
                driver: 'mysql',
                database: 'tenant_claim_race',
                host: 'claim-race.example.test',
            ),
            defaults: DatabaseTargetConfiguration::fromLaravelConfiguration($tenantConfiguration),
            central: DatabaseTargetConfiguration::fromLaravelConfiguration($centralConfiguration),
            sqliteRoot: $this->tenantDatabaseRoot,
            hostResolver: resolve(DatabaseHostResolver::class),
        );
        $claimFingerprint = $target->claimFingerprints()[0];
        $eventName = 'eloquent.creating: '.Shop::class;
        Event::listen($eventName, static function (Shop $shop) use ($claimFingerprint): void {
            $competingShopId = (string) Str::uuid();
            DB::connection('central')->table('shops')->insert([
                'id' => $competingShopId,
                'name' => 'Competing Claim Shop',
                'slug' => 'competing-claim-shop',
                'status' => ShopStatus::Provisioning->value,
                'database_driver' => $shop->database_driver,
                'database_name' => $shop->database_name,
                'database_target_fingerprint' => hash('sha256', 'different complete endpoint set'),
                'timezone' => 'Asia/Karachi',
                'currency' => 'PKR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('central')->table('shop_database_target_claims')->insert([
                'shop_id' => $competingShopId,
                'fingerprint' => $claimFingerprint,
            ]);
        });

        try {
            $caughtException = $this->assertTenantTargetConflict(
                fn (): Shop => $this->registerShop(
                    databaseDriver: 'mysql',
                    databaseName: 'tenant_claim_race',
                    databaseHost: 'claim-race.example.test',
                ),
                'The tenant database target is already assigned to another shop.',
            );
        } finally {
            Event::forget($eventName);
        }

        $this->assertNull($caughtException->getPrevious());
        $this->assertSame([], Shop::query()->pluck('slug')->all());
        $this->assertSame(0, DB::connection('central')->table('shop_database_target_claims')->count());
    }

    public function test_claim_insert_failure_rolls_back_the_shop_and_every_claim(): void
    {
        DB::connection('central')->unprepared(<<<'SQL'
            CREATE TRIGGER reject_shop_database_target_claim
            BEFORE INSERT ON shop_database_target_claims
            BEGIN
                SELECT RAISE(ABORT, 'forced claim publication failure');
            END
            SQL);

        try {
            $this->registerShop(
                slug: 'atomic-claim-publication',
                databaseDriver: 'mysql',
                databaseName: 'atomic_claim_publication',
                databaseHost: '192.0.2.97',
            );
            $this->fail('A forced claim publication failure was ignored.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced claim publication failure', $exception->getMessage());
        }

        $this->assertDatabaseMissing('shops', ['slug' => 'atomic-claim-publication'], 'central');
        $this->assertDatabaseCount('shop_database_target_claims', 0, 'central');
    }

    public function test_validated_connection_rejects_a_missing_or_extra_owned_claim(): void
    {
        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'exact_claim_ownership',
            databaseHost: '192.0.2.98',
        );
        $registeredClaim = $shop->databaseTargetClaims()->firstOrFail();
        $registeredClaim->delete();
        DB::connection('central')->table('shop_database_target_claims')->insert([
            'shop_id' => $shop->getKey(),
            'fingerprint' => hash('sha256', 'unexpected replacement claim'),
        ]);

        $this->assertTenantTargetConflict(
            fn () => $shop->validatedDatabaseConnection(),
            'The tenant database target identity changed after registration.',
        );
    }

    public function test_hard_deletion_cannot_release_database_target_claims(): void
    {
        $shop = Shop::factory()->create();
        $shopId = (string) $shop->getKey();
        $claimCount = $shop->databaseTargetClaims()->count();

        try {
            $shop->forceDeleteQuietly();
            $this->fail('A shop database reservation was hard deleted.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Shop hard deletion requires the future verified tenant cleanup workflow.',
                $exception->getMessage(),
            );
        }

        try {
            Shop::query()->whereKey($shopId)->forceDelete();
            $this->fail('A query builder bypass released a shop database reservation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('shops', ['id' => $shopId], 'central');
        $this->assertDatabaseCount('shop_database_target_claims', $claimCount, 'central');
    }

    public function test_connection_snapshot_resolves_database_hosts_once(): void
    {
        $resolver = new class implements DatabaseHostResolver
        {
            public int $calls = 0;

            public function resolve(string $host): array
            {
                $this->calls++;

                return ['192.0.2.99'];
            }
        };
        app()->instance(DatabaseHostResolver::class, $resolver);
        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'single_resolution',
            databaseHost: 'single-resolution.example.test',
        );
        $resolver->calls = 0;

        $snapshot = $shop->validatedDatabaseConnection();

        $this->assertSame(1, $resolver->calls);
        $this->assertSame('mysql', $snapshot->target()->driver);
        $this->assertSame('single_resolution', $snapshot->connectionOverrides()['database']);
    }

    public function test_sqlite_path_unique_race_is_translated_to_a_tenant_target_conflict(): void
    {
        $databasePath = $this->newTenantDatabasePath('locator-race-');
        $eventName = 'eloquent.creating: '.Shop::class;
        Event::listen($eventName, static function (Shop $shop): void {
            DB::connection('central')->table('shops')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'Competing Shop',
                'slug' => 'competing-shop',
                'status' => ShopStatus::Provisioning->value,
                'database_driver' => $shop->database_driver,
                'database_name' => $shop->database_name,
                'database_target_fingerprint' => hash('sha256', 'different physical file identity'),
                'database_target_locator_fingerprint' => $shop->database_target_locator_fingerprint,
                'timezone' => 'Asia/Karachi',
                'currency' => 'PKR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $caughtException = $this->assertTenantTargetConflict(
                fn (): Shop => $this->registerShop(databaseName: $databasePath),
                'The tenant database target is already assigned to another shop.',
            );
        } finally {
            Event::forget($eventName);
        }

        $this->assertNull($caughtException->getPrevious());
        $this->assertSame(0, Shop::query()->count());
    }

    public function test_shop_lifecycle_transitions_are_explicit(): void
    {
        $shop = $this->registerShop();

        $this->travelTo('2026-09-02 11:00:00');
        $shop->markProvisioningFailed('Tenant migration timed out.');
        $this->assertSame(ShopStatus::Failed, $shop->fresh()->status);
        $this->assertSame('Tenant migration timed out.', $shop->fresh()->provisioning_failure_message);
        $this->assertSame('2026-09-02 11:00:00', $shop->fresh()->provisioning_failed_at?->format('Y-m-d H:i:s'));

        $shop->retryProvisioning();
        $this->assertSame(ShopStatus::Provisioning, $shop->fresh()->status);
        $this->assertNull($shop->fresh()->provisioning_failure_message);
        $this->assertNull($shop->fresh()->provisioning_failed_at);

        $this->travelTo('2026-09-02 11:30:00');
        $shop->markActive();
        $this->assertSame(ShopStatus::Active, $shop->fresh()->status);
        $this->assertSame('2026-09-02 11:30:00', $shop->fresh()->provisioned_at?->format('Y-m-d H:i:s'));

        $shop->suspend();
        $this->assertSame(ShopStatus::Suspended, $shop->fresh()->status);

        $shop->reactivate();
        $this->assertSame(ShopStatus::Active, $shop->fresh()->status);

        try {
            $shop->markProvisioningFailed('Invalid late failure.');
            $this->fail('An active shop accepted an invalid lifecycle transition.');
        } catch (LogicException $exception) {
            $this->assertSame('Cannot transition shop from [active] to [failed].', $exception->getMessage());
        }
    }

    public function test_provisioning_failure_preserves_the_original_error_after_sqlite_replacement(): void
    {
        $databasePath = $this->newTenantDatabasePath('failed-replaced-sqlite-');
        $shop = $this->registerShop(databaseName: $databasePath);
        $registeredFingerprint = $shop->database_target_fingerprint;
        $registeredLocatorFingerprint = $shop->database_target_locator_fingerprint;

        File::delete($databasePath);
        File::put($databasePath, 'replacement before failure handling');

        $shop->markProvisioningFailed('Original migration failure.');

        $persistedShop = $shop->fresh();
        $this->assertSame(ShopStatus::Failed, $persistedShop->status);
        $this->assertSame('Original migration failure.', $persistedShop->provisioning_failure_message);
        $this->assertSame($registeredFingerprint, $persistedShop->database_target_fingerprint);
        $this->assertSame($registeredLocatorFingerprint, $persistedShop->database_target_locator_fingerprint);
    }

    public function test_provisioning_failure_preserves_the_original_error_after_dns_retargeting(): void
    {
        $resolver = new class implements DatabaseHostResolver
        {
            /** @var list<string> */
            public array $addresses = ['192.0.2.92'];

            public function resolve(string $host): array
            {
                return $host === 'provisioning-failure.example.test' ? $this->addresses : [];
            }
        };
        app()->instance(DatabaseHostResolver::class, $resolver);
        $shop = $this->registerShop(
            databaseDriver: 'mysql',
            databaseName: 'tenant_failure_dns',
            databaseHost: 'provisioning-failure.example.test',
        );
        $registeredFingerprint = $shop->database_target_fingerprint;
        $resolver->addresses = ['192.0.2.93'];

        $shop->markProvisioningFailed('Original connection failure.');

        $persistedShop = $shop->fresh();
        $this->assertSame(ShopStatus::Failed, $persistedShop->status);
        $this->assertSame('Original connection failure.', $persistedShop->provisioning_failure_message);
        $this->assertSame($registeredFingerprint, $persistedShop->database_target_fingerprint);
    }

    public function test_database_target_is_frozen_during_an_active_provisioning_attempt(): void
    {
        $initialDatabasePath = $this->newTenantDatabasePath('frozen-provisioning-');
        $updatedDatabasePath = $this->newTenantDatabasePath('forbidden-provisioning-update-');
        $shop = $this->registerShop(databaseName: $initialDatabasePath);

        try {
            $shop->updateProvisioningTarget(
                slug: 'changed-during-provisioning',
                databaseDriver: 'sqlite',
                databaseName: $updatedDatabasePath,
            );
            $this->fail('A shop changed its database target during an active provisioning attempt.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Shop database targets can only be changed after provisioning fails and before retry.',
                $exception->getMessage(),
            );
        }

        $persistedShop = $shop->fresh();

        $this->assertSame($initialDatabasePath, $persistedShop->database_name);
        $this->assertSame('alpha-workshop', $persistedShop->slug);
        $this->assertSame(ShopStatus::Provisioning, $persistedShop->status);
    }

    public function test_active_shop_slug_database_target_and_status_cannot_be_changed_directly(): void
    {
        $initialDatabasePath = $this->newTenantDatabasePath('tenant-initial-');
        $updatedDatabasePath = $this->newTenantDatabasePath('tenant-updated-');
        $shop = $this->registerShop(databaseName: $initialDatabasePath);
        $shop->markProvisioningFailed('Change the target before retry.');
        $shop->updateProvisioningTarget(
            slug: 'beta-workshop',
            databaseDriver: 'sqlite',
            databaseName: $updatedDatabasePath,
        );
        $shop->retryProvisioning();
        $shop->markActive();

        try {
            $shop->updateProvisioningTarget(
                slug: 'moved-workshop',
                databaseDriver: 'mysql',
                databaseName: 'moved_database',
            );
            $this->fail('An active shop accepted a new database target.');
        } catch (LogicException $exception) {
            $this->assertSame('A provisioned shop database target is immutable.', $exception->getMessage());
        }

        $shop = $shop->fresh();
        $shop->slug = 'direct-write';

        try {
            $shop->save();
            $this->fail('An active shop accepted a direct slug write.');
        } catch (LogicException $exception) {
            $this->assertSame('Shop database targets must be changed through updateProvisioningTarget().', $exception->getMessage());
        }

        $shop = $shop->fresh();
        $shop->status = ShopStatus::Suspended;

        try {
            $shop->save();
            $this->fail('A shop accepted a direct status write.');
        } catch (LogicException $exception) {
            $this->assertSame('Shop status must be changed through a lifecycle method.', $exception->getMessage());
        }

        $this->assertSame('beta-workshop', $shop->fresh()->slug);
        $this->assertSame($updatedDatabasePath, $shop->fresh()->database_name);
        $this->assertSame(ShopStatus::Active, $shop->fresh()->status);
    }

    public function test_stale_shop_cannot_change_database_target_after_competing_activation(): void
    {
        $databasePath = $this->newTenantDatabasePath('tenant-stale-');
        $shop = $this->registerShop(databaseName: $databasePath);
        $staleShop = $shop->fresh();

        $shop->markActive();

        try {
            $staleShop->updateProvisioningTarget(
                slug: 'stale-workshop',
                databaseDriver: 'mysql',
                databaseName: 'stale_database',
            );
            $this->fail('A stale shop changed the database target after activation.');
        } catch (LogicException $exception) {
            $this->assertSame('A provisioned shop database target is immutable.', $exception->getMessage());
        }

        $persistedShop = $shop->fresh();

        $this->assertSame('alpha-workshop', $persistedShop->slug);
        $this->assertSame($databasePath, $persistedShop->database_name);
        $this->assertSame(ShopStatus::Active, $persistedShop->status);
    }

    public function test_stale_shop_cannot_overwrite_a_competing_lifecycle_transition(): void
    {
        $shop = $this->registerShop();
        $staleShop = $shop->fresh();

        $shop->markActive();

        try {
            $staleShop->markProvisioningFailed('Stale provisioning worker failed.');
            $this->fail('A stale shop overwrote a completed activation.');
        } catch (LogicException $exception) {
            $this->assertSame('Cannot transition shop from [active] to [failed].', $exception->getMessage());
        }

        $persistedShop = $shop->fresh();

        $this->assertSame(ShopStatus::Active, $persistedShop->status);
        $this->assertNull($persistedShop->provisioning_failed_at);
        $this->assertNull($persistedShop->provisioning_failure_message);
    }

    public function test_support_access_session_is_uuid_guarded_and_ended_explicitly(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();
        $session = ShopAccessSession::start(
            platformUser: $platformUser,
            shop: $shop,
            reason: 'Review stock discrepancy',
            ipAddress: '203.0.113.20',
            userAgent: 'Support Browser',
        );

        try {
            $session->fill([
                'platform_user_id' => 999,
                'shop_id' => (string) Str::uuid(),
                'started_at' => now()->subDay(),
                'ended_at' => now(),
                'reason' => 'Tampered reason',
            ]);
            $this->fail('A support audit session accepted mass assignment.');
        } catch (MassAssignmentException) {
            // A support audit can only be created and ended through its transitions.
        }

        $this->assertTrue(Str::isUuid($session->getKey()));
        $this->assertSame($platformUser->getKey(), $session->platform_user_id);
        $this->assertSame($shop->getKey(), $session->shop_id);
        $this->assertSame('Review stock discrepancy', $session->reason);
        $this->assertNull($session->ended_at);

        $session->ended_at = now();

        try {
            $session->save();
            $this->fail('A support audit session accepted a direct end timestamp.');
        } catch (LogicException $exception) {
            $this->assertSame('Support access sessions are immutable except for the end transition.', $exception->getMessage());
        }

        $session = $session->fresh();

        $this->travelTo('2026-09-02 12:00:00');
        $session->end();
        $firstEndedAt = $session->fresh()->ended_at;
        $this->assertSame('2026-09-02 12:00:00', $firstEndedAt?->format('Y-m-d H:i:s'));

        $this->travelTo('2026-09-02 12:30:00');
        $session->end();
        $this->assertTrue($firstEndedAt?->equalTo($session->fresh()->ended_at));

        try {
            $session->delete();
            $this->fail('A support audit session was deleted.');
        } catch (LogicException $exception) {
            $this->assertSame('Support access sessions are append-only and cannot be deleted.', $exception->getMessage());
        }
    }

    public function test_support_access_sessions_must_start_through_the_controlled_factory(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();
        $session = new ShopAccessSession;
        $session->platformUser()->associate($platformUser);
        $session->shop()->associate($shop);
        $session->forceFill(['started_at' => now()]);

        try {
            $session->save();
            $this->fail('A support access session bypassed the controlled start boundary.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Support access sessions must be started through start().',
                $exception->getMessage(),
            );
        }

        $this->assertFalse($session->exists);
    }

    public function test_stale_support_access_session_preserves_the_first_end_timestamp(): void
    {
        $session = ShopAccessSession::start(
            PlatformUser::factory()->create(),
            Shop::factory()->create(),
        );
        $firstWorker = $session->fresh();
        $staleWorker = $session->fresh();

        $this->travelTo('2026-09-02 14:00:00');
        $firstWorker->end();

        $this->travelTo('2026-09-02 14:30:00');
        $staleWorker->end();

        $this->assertSame('2026-09-02 14:00:00', $firstWorker->ended_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-02 14:00:00', $staleWorker->ended_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-02 14:00:00', $session->fresh()->ended_at?->format('Y-m-d H:i:s'));
    }

    public function test_support_audit_history_prevents_platform_user_deletion(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();
        $session = ShopAccessSession::start($platformUser, $shop);

        try {
            $platformUser->delete();
            $this->fail('A platform user with support audit history was deleted.');
        } catch (QueryException) {
            // The foreign key must preserve the audit history.
        }

        $this->assertModelExists($platformUser);
        $this->assertModelExists($session);
    }

    public function test_support_audit_history_prevents_shop_force_deletion(): void
    {
        $platformUser = PlatformUser::factory()->create();
        $shop = Shop::factory()->create();
        $session = ShopAccessSession::start($platformUser, $shop);

        try {
            $shop->forceDelete();
            $this->fail('A shop with support audit history was force deleted.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Shop hard deletion requires the future verified tenant cleanup workflow.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('shops', ['id' => $shop->getKey()], 'central');
        $this->assertModelExists($session);
    }

    public function test_lifecycle_recorder_writes_redacted_typed_central_activity(): void
    {
        $platformUser = PlatformUser::factory()->create(['name' => 'Platform Operator']);
        $shop = Shop::factory()->create();
        $this->travelTo('2026-09-02 13:00:00');

        $activity = (new RecordShopLifecycleActivity)->handle(
            shop: $shop,
            event: ShopLifecycleEvent::Suspended,
            actor: $platformUser,
            metadata: [
                'reason_code' => 'maintenance',
                'reason' => 'Maintenance window',
                'database_password' => 'must-not-be-recorded',
                'connection' => ['password' => 'also-secret', 'driver' => 'sqlite'],
            ],
        );

        $this->assertTrue(Str::isUuid($activity->getKey()));
        $this->assertSame('central', $activity->getConnectionName());
        $this->assertSame(ShopLifecycleEvent::Suspended, $activity->event);
        $this->assertSame('Platform Operator', $activity->actor_name);
        $this->assertSame('2026-09-02 13:00:00', $activity->occurred_at?->format('Y-m-d H:i:s'));
        $this->assertSame(['reason_code' => 'maintenance'], $activity->metadata);
        $this->assertTrue($activity->shop()->firstOrFail()->is($shop));
        $this->assertTrue($activity->platformUser()->firstOrFail()->is($platformUser));
        $this->assertTrue($shop->lifecycleActivities()->firstOrFail()->is($activity));
        $this->assertTrue($platformUser->shopLifecycleActivities()->firstOrFail()->is($activity));
    }

    public function test_lifecycle_recorder_persists_only_safe_event_specific_metadata(): void
    {
        $activity = (new RecordShopLifecycleActivity)->handle(
            shop: Shop::factory()->create(),
            event: ShopLifecycleEvent::ProvisioningFailed,
            metadata: [
                'attempt' => 2,
                'failure_stage' => 'mysql://operator:database-password@db.internal/tenant',
                'error_code' => 'eyJhbGciOiJIUzI1NiJ9.payload.signature',
                'database_url' => 'mysql://operator:password@db.internal/tenant',
                'tokens' => ['secret-token-value'],
                'notes' => 'This unknown field must not persist.',
                'connection' => [
                    'secret' => 'nested-secret',
                    'token' => 'nested-token',
                    'password' => 'nested-password',
                    'url' => 'https://user:password@example.com',
                ],
            ],
        );

        $storedMetadata = DB::connection('central')
            ->table('shop_lifecycle_activities')
            ->where('id', $activity->getKey())
            ->value('metadata');

        $this->assertSame(['attempt' => 2], $activity->metadata);
        $this->assertSame(['attempt' => 2], json_decode((string) $storedMetadata, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $expectedMetadata
     */
    #[DataProvider('safeLifecycleMetadata')]
    public function test_lifecycle_recorder_accepts_only_the_documented_schema_for_each_event(
        ShopLifecycleEvent $event,
        array $metadata,
        array $expectedMetadata,
    ): void {
        $activity = (new RecordShopLifecycleActivity)->handle(
            shop: Shop::factory()->create(),
            event: $event,
            metadata: [...$metadata, 'unknown' => 'discard-me'],
        );

        $this->assertSame($expectedMetadata, $activity->metadata);
    }

    public function test_lifecycle_activity_cannot_be_modified_or_deleted(): void
    {
        $activity = (new RecordShopLifecycleActivity)->handle(
            shop: Shop::factory()->create(),
            event: ShopLifecycleEvent::ProvisioningStarted,
        );

        $activity->event = ShopLifecycleEvent::ProvisioningFailed;

        try {
            $activity->save();
            $this->fail('A lifecycle activity was modified.');
        } catch (LogicException $exception) {
            $this->assertSame('Shop lifecycle activities are append-only and cannot be modified.', $exception->getMessage());
        }

        $activity = $activity->fresh();

        try {
            $activity->delete();
            $this->fail('A lifecycle activity was deleted.');
        } catch (LogicException $exception) {
            $this->assertSame('Shop lifecycle activities are append-only and cannot be deleted.', $exception->getMessage());
        }

        $this->assertModelExists($activity);
    }

    /** @return array<string, array{string}> */
    public static function unsafeMySqlDatabaseNames(): array
    {
        return [
            'empty' => [''],
            'hyphen' => ['tenant-alpha'],
            'path separator' => ['tenant/alpha'],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    /** @return array<string, array{string}> */
    public static function unsafeSqliteDatabasePaths(): array
    {
        return [
            'relative path' => ['tenant-alpha.sqlite'],
            'parent traversal' => ['../tenant-alpha.sqlite'],
            'memory database' => [':memory:'],
            'URI filename' => ['file:///tmp/tenant-alpha.sqlite'],
        ];
    }

    /** @return array<string, array{ShopLifecycleEvent, array<string, mixed>, array<string, mixed>}> */
    public static function safeLifecycleMetadata(): array
    {
        return [
            'provisioning started' => [
                ShopLifecycleEvent::ProvisioningStarted,
                ['attempt' => 1, 'database_driver' => 'mysql'],
                ['attempt' => 1, 'database_driver' => 'mysql'],
            ],
            'provisioning succeeded' => [
                ShopLifecycleEvent::ProvisioningSucceeded,
                ['attempt' => 1, 'database_driver' => 'sqlite', 'migration_batch' => 2, 'duration_ms' => 125],
                ['attempt' => 1, 'database_driver' => 'sqlite', 'migration_batch' => 2, 'duration_ms' => 125],
            ],
            'provisioning failed' => [
                ShopLifecycleEvent::ProvisioningFailed,
                ['attempt' => 2, 'failure_stage' => 'migration', 'error_code' => 'TENANT_MIGRATION_FAILED'],
                ['attempt' => 2, 'failure_stage' => 'migration', 'error_code' => 'TENANT_MIGRATION_FAILED'],
            ],
            'suspended' => [
                ShopLifecycleEvent::Suspended,
                ['reason_code' => 'maintenance'],
                ['reason_code' => 'maintenance'],
            ],
            'reactivated' => [
                ShopLifecycleEvent::Reactivated,
                ['reason_code' => 'operator_request'],
                ['reason_code' => 'operator_request'],
            ],
            'feature enabled' => [
                ShopLifecycleEvent::FeatureEnabled,
                ['module_key' => 'inventory', 'reason_code' => 'plan_change'],
                ['module_key' => 'inventory', 'reason_code' => 'plan_change'],
            ],
            'feature disabled' => [
                ShopLifecycleEvent::FeatureDisabled,
                ['module_key' => 'inventory', 'reason_code' => 'plan_change'],
                ['module_key' => 'inventory', 'reason_code' => 'plan_change'],
            ],
            'migration succeeded' => [
                ShopLifecycleEvent::MigrationSucceeded,
                ['migration' => '2026_09_01_123456_create_items.php', 'batch' => 3, 'duration_ms' => 250],
                ['migration' => '2026_09_01_123456_create_items.php', 'batch' => 3, 'duration_ms' => 250],
            ],
            'migration failed' => [
                ShopLifecycleEvent::MigrationFailed,
                ['migration' => '2026_09_01_123456_create_items.php', 'batch' => 3, 'error_code' => 'SQL_FAILED'],
                ['migration' => '2026_09_01_123456_create_items.php', 'batch' => 3, 'error_code' => 'SQL_FAILED'],
            ],
            'existing database adopted' => [
                ShopLifecycleEvent::ExistingDatabaseAdopted,
                ['database_driver' => 'mysql', 'table_count' => 17, 'owner_linked' => true],
                ['database_driver' => 'mysql', 'table_count' => 17, 'owner_linked' => true],
            ],
        ];
    }

    private function registerShop(
        string $databaseDriver = 'sqlite',
        ?string $databaseName = null,
        string $slug = 'alpha-workshop',
        array|string|null $databaseHost = null,
        ?int $databasePort = null,
        ?string $databaseUsername = null,
        ?string $databasePassword = null,
        ?string $databaseSocket = null,
    ): Shop {
        $databaseName ??= $databaseDriver === 'sqlite'
            ? $this->newTenantDatabasePath('tenant-alpha-')
            : 'tenant_alpha';

        return Shop::registerForProvisioning(
            name: 'Alpha Workshop',
            slug: $slug,
            databaseDriver: $databaseDriver,
            databaseName: $databaseName,
            databaseHost: $databaseHost,
            databasePort: $databasePort,
            databaseUsername: $databaseUsername,
            databasePassword: $databasePassword,
            databaseSocket: $databaseSocket,
        );
    }

    private function assertTenantTargetConflict(
        callable $operation,
        string $expectedMessage,
    ): TenantDatabaseTargetConflict {
        $caughtException = null;

        try {
            $operation();
        } catch (Throwable $exception) {
            $caughtException = $exception;
        }

        $this->assertNotNull($caughtException, 'A conflicting tenant database target was accepted.');
        $this->assertInstanceOf(
            TenantDatabaseTargetConflict::class,
            $caughtException,
            $caughtException->getMessage(),
        );
        $this->assertSame($expectedMessage, $caughtException->getMessage());

        return $caughtException;
    }

    /** @param array<string, list<string>> $addressesByHost */
    private function resolveDatabaseHosts(array $addressesByHost): void
    {
        app()->instance(
            DatabaseHostResolver::class,
            new class($addressesByHost) implements DatabaseHostResolver
            {
                /** @param array<string, list<string>> $addressesByHost */
                public function __construct(private readonly array $addressesByHost) {}

                public function resolve(string $host): array
                {
                    return $this->addressesByHost[$host] ?? [];
                }
            },
        );
    }

    private function newTemporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.Str::uuid();

        if (! File::makeDirectory($path, 0700, true)) {
            throw new RuntimeException('Unable to create a temporary directory.');
        }

        $this->temporaryDirectories[] = $path;

        return $path;
    }

    private function filesystemIdentity(string $path): ?string
    {
        $metadata = @stat($path);

        if (! is_array($metadata)
            || ! is_int($metadata['dev'] ?? null)
            || ! is_int($metadata['ino'] ?? null)
            || ($metadata['dev'] === 0 && $metadata['ino'] === 0)) {
            return null;
        }

        return $metadata['dev'].':'.$metadata['ino'];
    }

    private function newTenantDatabasePath(string $prefix, bool $create = true): string
    {
        $path = $this->tenantDatabaseRoot.DIRECTORY_SEPARATOR.$prefix.Str::uuid().'.sqlite';

        if ($create && File::put($path, '') === false) {
            throw new RuntimeException('Unable to create a temporary tenant SQLite database.');
        }

        $this->temporaryDatabasePaths[] = $path;

        return $path;
    }

    private function createSqliteDatabaseIdentitySnapshot(string $databasePath): SqliteDatabaseIdentitySnapshot
    {
        $databaseHandle = @fopen($databasePath, 'x+b');

        if (! is_resource($databaseHandle)) {
            throw new RuntimeException('Unable to exclusively create a temporary tenant SQLite database.');
        }

        try {
            return SqliteDatabaseIdentitySnapshot::capture($databasePath, $databaseHandle);
        } finally {
            fclose($databaseHandle);
        }
    }

    private function newTemporaryDatabasePath(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary SQLite database.');
        }

        $this->temporaryDatabasePaths[] = $path;

        return $path;
    }

    private function writeDatabaseMarker(string $databasePath, string $marker): void
    {
        $connection = DB::build([
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        try {
            $connection->statement('CREATE TABLE connection_marker (name VARCHAR NOT NULL)');
            $connection->table('connection_marker')->insert(['name' => $marker]);
        } finally {
            DB::purge($connection->getName());
        }
    }

    /** @param array<string, mixed> $tenantTemplate */
    private function readDatabaseMarker(array $tenantTemplate, Shop $shop): string
    {
        $connection = DB::build(array_replace($tenantTemplate, $shop->databaseConfig()));

        try {
            return (string) $connection->table('connection_marker')->value('name');
        } finally {
            DB::purge($connection->getName());
        }
    }

    /** @return array<string, mixed> */
    private function tenantTemplateWithDatabaseUrl(string $databaseUrl): array
    {
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$application = require $argv[1].'/bootstrap/app.php';
$configuration = require $argv[1].'/config/database.php';
echo json_encode($configuration['connections']['tenant'], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process(
            [PHP_BINARY, '-r', $script, base_path()],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'DB_URL' => $databaseUrl,
            ],
        );
        $process->mustRun();
        $tenantTemplate = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($tenantTemplate)) {
            throw new RuntimeException('The tenant connection template was not an array.');
        }

        return $tenantTemplate;
    }

    /** @return array{configured_url: ?string, database: string, marker: ?string} */
    private function centralConnectionWithGenericDatabaseUrl(
        string $centralDatabasePath,
        string $genericDatabaseUrl,
    ): array {
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$application = require $argv[1].'/bootstrap/app.php';
$application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = $application->make('db')->connection('central');
$marker = $connection->getSchemaBuilder()->hasTable('connection_marker')
    ? $connection->table('connection_marker')->value('name')
    : null;
echo json_encode([
    'configured_url' => $application->make('config')->get('database.connections.central.url'),
    'database' => $connection->getDatabaseName(),
    'marker' => $marker,
], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process(
            [PHP_BINARY, '-r', $script, base_path()],
            base_path(),
            [
                'APP_CONFIG_CACHE' => false,
                'APP_ENV' => 'testing',
                'CENTRAL_DB_CONNECTION' => 'sqlite',
                'CENTRAL_DB_DATABASE' => $centralDatabasePath,
                'CENTRAL_DB_URL' => false,
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'DB_URL' => $genericDatabaseUrl,
            ],
        );
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($result)) {
            throw new RuntimeException('The central connection result was not an array.');
        }

        return $result;
    }
}
