<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Tenancy\RecordShopLifecycleActivity;
use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopAccessSession;
use App\Models\Central\ShopFeature;
use App\Models\Central\ShopHealthSnapshot;
use App\Models\Central\ShopLifecycleActivity;
use App\Models\Central\ShopOwner;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CentralDomainTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryDatabasePaths = [];

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->assertTrue($shop->owner()->firstOrFail()->is($owner));
        $this->assertTrue($shop->features()->firstOrFail()->is($feature));
        $this->assertTrue($shop->accessSessions()->firstOrFail()->is($accessSession));
        $this->assertTrue($shop->healthSnapshot()->firstOrFail()->is($healthSnapshot));
        $this->assertTrue($owner->shop()->firstOrFail()->is($shop));
        $this->assertTrue($feature->shop()->firstOrFail()->is($shop));
        $this->assertTrue($accessSession->shop()->firstOrFail()->is($shop));
        $this->assertTrue($accessSession->platformUser()->firstOrFail()->is($platformUser));
        $this->assertTrue($healthSnapshot->shop()->firstOrFail()->is($shop));
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
        $shopADatabasePath = $this->newTemporaryDatabasePath('shop-a-');
        $shopBDatabasePath = $this->newTemporaryDatabasePath('shop-b-');
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

    public function test_shop_database_config_returns_decrypted_connection_overrides(): void
    {
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

    public function test_platform_guard_uses_the_platform_user_provider(): void
    {
        $provider = Auth::guard('platform')->getProvider();

        $this->assertSame(PlatformUser::class, $provider->getModel());
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
        $platformUser = PlatformUser::factory()->create();

        $platformUser->deactivate();
        $this->assertFalse($platformUser->fresh()->is_active);

        $platformUser->activate();
        $this->assertTrue($platformUser->fresh()->is_active);

        $this->travelTo('2026-09-02 10:30:00');
        $platformUser->recordSuccessfulLogin();

        $this->assertSame('2026-09-02 10:30:00', $platformUser->fresh()->last_login_at?->format('Y-m-d H:i:s'));
    }

    public function test_shop_lifecycle_and_database_fields_are_not_mass_assignable(): void
    {
        $shop = $this->registerShop();

        $shop->fill([
            'name' => 'Allowed Shop Name',
            'slug' => 'attacker-slug',
            'status' => ShopStatus::Active,
            'database_driver' => 'mysql',
            'database_name' => 'attacker_database',
            'database_host' => 'attacker.internal',
            'provisioned_at' => now(),
            'provisioning_failure_message' => 'Forged failure',
        ]);

        $this->assertSame('Allowed Shop Name', $shop->name);
        $this->assertSame('alpha-workshop', $shop->slug);
        $this->assertSame(ShopStatus::Provisioning, $shop->status);
        $this->assertSame('sqlite', $shop->database_driver);
        $this->assertSame('tenant-alpha.sqlite', $shop->database_name);
        $this->assertNull($shop->database_host);
        $this->assertNull($shop->provisioned_at);
        $this->assertNull($shop->provisioning_failure_message);
    }

    public function test_shop_registration_rejects_unsupported_database_drivers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported shop database driver [pgsql].');

        $this->registerShop(databaseDriver: 'pgsql');
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

    public function test_active_shop_slug_database_target_and_status_cannot_be_changed_directly(): void
    {
        $shop = $this->registerShop();
        $shop->updateProvisioningTarget(
            slug: 'beta-workshop',
            databaseDriver: 'sqlite',
            databaseName: 'tenant-beta.sqlite',
        );
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
        $this->assertSame('tenant-beta.sqlite', $shop->fresh()->database_name);
        $this->assertSame(ShopStatus::Active, $shop->fresh()->status);
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
        } catch (QueryException) {
            // The foreign key must preserve the audit history.
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
        $this->assertSame([
            'reason' => 'Maintenance window',
            'connection' => ['driver' => 'sqlite'],
        ], $activity->metadata);
        $this->assertTrue($activity->shop()->firstOrFail()->is($shop));
        $this->assertTrue($activity->platformUser()->firstOrFail()->is($platformUser));
        $this->assertTrue($shop->lifecycleActivities()->firstOrFail()->is($activity));
        $this->assertTrue($platformUser->shopLifecycleActivities()->firstOrFail()->is($activity));
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

    private function registerShop(
        string $databaseDriver = 'sqlite',
        string $databaseName = 'tenant-alpha.sqlite',
    ): Shop {
        return Shop::registerForProvisioning(
            name: 'Alpha Workshop',
            slug: 'alpha-workshop',
            databaseDriver: $databaseDriver,
            databaseName: $databaseName,
        );
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
}
