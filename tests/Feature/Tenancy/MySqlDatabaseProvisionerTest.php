<?php

namespace Tests\Feature\Tenancy;

use App\Enums\ShopLifecycleEvent;
use App\Exceptions\TenantProvisioningException;
use App\Models\Central\Shop;
use App\Tenancy\Provisioning\LaravelMySqlServerConnectionFactory;
use App\Tenancy\Provisioning\MySqlDatabaseProvisioner;
use App\Tenancy\Provisioning\MySqlServerConnection;
use App\Tenancy\Provisioning\MySqlServerConnectionFactory;
use App\Tenancy\Provisioning\TenantProvisioningCheckpoint;
use App\Tenancy\Provisioning\TenantProvisioningHook;
use App\Tenancy\Provisioning\TenantProvisioningInterrupted;
use App\Tenancy\Provisioning\TenantProvisioningLease;
use App\Tenancy\TenantConnectionConfigurationFactory;
use App\Tenancy\ValidatedTenantConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class MySqlDatabaseProvisionerTest extends TestCase
{
    public function test_remote_tcp_mysql_provisioning_is_release_gated_by_default(): void
    {
        config()->set('database.tenant_mysql_remote_provisioning_enabled', false);
        $suffix = Str::lower((string) Str::ulid());
        $shop = Shop::registerForProvisioning(
            name: 'MySQL Contract Shop',
            slug: 'mysql-contract-'.$suffix,
            databaseDriver: 'mysql',
            databaseName: 'tenant_'.$suffix,
            databaseHost: 'localhost',
            databaseUsername: 'provisioner',
            databasePassword: 'not-logged',
        );

        try {
            app(MySqlServerConnectionFactory::class)->open($shop->validatedDatabaseConnection());
            $this->fail('Remote TCP MySQL provisioning must remain release-gated.');
        } catch (TenantProvisioningException $exception) {
            $this->assertSame('MYSQL_REMOTE_PROVISIONING_DISABLED', $exception->errorCode);
        }

        $this->assertSame([], array_filter(
            array_keys((array) config('database.connections')),
            static fn (string $name): bool => str_starts_with($name, 'tenant_mysql_server_'),
        ));
    }

    public function test_absent_database_uses_the_driver_contract_then_publishes_a_receipt(): void
    {
        $suffix = Str::lower((string) Str::ulid());
        $database = 'tenant_'.$suffix;
        $shop = Shop::registerForProvisioning(
            name: 'MySQL Creation Contract Shop',
            slug: 'mysql-create-'.$suffix,
            databaseDriver: 'mysql',
            databaseName: $database,
            databaseHost: 'localhost',
            databaseUsername: 'provisioner',
            databasePassword: 'not-logged',
        );
        $server = new class implements MySqlServerConnection
        {
            /** @var list<string> */
            public array $existenceChecks = [];

            /** @var list<string> */
            public array $creates = [];

            public bool $closed = false;

            public function databaseExists(#[\SensitiveParameter] string $database): bool
            {
                $this->existenceChecks[] = $database;

                return false;
            }

            public function createDatabase(#[\SensitiveParameter] string $database): void
            {
                $this->creates[] = $database;
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
        $this->app->instance(MySqlServerConnectionFactory::class, new class($server) implements MySqlServerConnectionFactory
        {
            public function __construct(private readonly MySqlServerConnection $server) {}

            public function open(
                #[\SensitiveParameter]
                ValidatedTenantConnection $snapshot,
            ): MySqlServerConnection {
                return $this->server;
            }
        });
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
            app(MySqlDatabaseProvisioner::class)->provision($shop, $this->lease());
            $this->fail('The hook should stop before any selected-schema connection.');
        } catch (TenantProvisioningInterrupted) {
        }

        $this->assertSame([$database], $server->existenceChecks);
        $this->assertSame([$database], $server->creates);
        $this->assertTrue($server->closed);
        $this->assertDatabaseHas('shop_lifecycle_activities', [
            'shop_id' => $shop->getKey(),
            'event' => ShopLifecycleEvent::TenantInstallationAuthorized->value,
        ], 'central');
        $receipt = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::TenantInstallationAuthorized->value)
            ->firstOrFail();
        $this->assertSame([
            'database_driver' => 'mysql',
            'reason_code' => 'exclusive_create',
            'target_fingerprint' => $shop->database_target_fingerprint,
        ], $receipt->metadata);
    }

    public function test_server_factory_preserves_authenticated_tls_hostname_and_options(): void
    {
        if (! defined('PDO::MYSQL_ATTR_SSL_CA')
            || ! defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            config()->set('database.tenant_mysql_remote_provisioning_enabled', true);
            $suffix = Str::lower((string) Str::ulid());
            $shop = Shop::registerForProvisioning(
                name: 'MySQL Missing TLS Contract Shop',
                slug: 'mysql-missing-tls-'.$suffix,
                databaseDriver: 'mysql',
                databaseName: 'tenant_'.$suffix,
                databaseHost: 'localhost',
                databaseUsername: 'provisioner',
                databasePassword: 'not-logged',
            );

            try {
                app(MySqlServerConnectionFactory::class)->open($shop->validatedDatabaseConnection());
                $this->fail('Remote provisioning cannot proceed without PDO TLS verification support.');
            } catch (TenantProvisioningException $exception) {
                $this->assertSame('MYSQL_AUTHENTICATED_TLS_REQUIRED', $exception->errorCode);
            }

            return;
        }

        $caFile = tempnam(sys_get_temp_dir(), 'tenant-ca-');

        if (! is_string($caFile)) {
            $this->fail('The CA fixture could not be created.');
        }

        try {
            config()->set('database.tenant_mysql_remote_provisioning_enabled', true);
            $template = (array) config('database.tenant_connection_template');
            $template['options'] = [
                PDO::MYSQL_ATTR_SSL_CA => $caFile,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ];
            config()->set('database.tenant_connection_template', $template);
            $suffix = Str::lower((string) Str::ulid());
            $shop = Shop::registerForProvisioning(
                name: 'MySQL TLS Contract Shop',
                slug: 'mysql-tls-'.$suffix,
                databaseDriver: 'mysql',
                databaseName: 'tenant_'.$suffix,
                databaseHost: 'localhost',
                databaseUsername: 'provisioner',
                databasePassword: 'not-logged',
            );
            $connection = $this->createMock(Connection::class);
            $databaseManager = $this->createMock(DatabaseManager::class);
            $databaseManager->expects($this->exactly(2))->method('purge');
            $databaseManager->expects($this->once())
                ->method('connection')
                ->with($this->callback(function (string $connectionName) use ($caFile): bool {
                    $configuration = config('database.connections.'.$connectionName);

                    $this->assertStringStartsWith('tenant_mysql_server_', $connectionName);
                    $this->assertIsArray($configuration);
                    $this->assertSame('information_schema', $configuration['database']);
                    $this->assertSame('localhost', $configuration['host']);
                    $this->assertSame($caFile, $configuration['options'][PDO::MYSQL_ATTR_SSL_CA]);
                    $this->assertTrue(
                        $configuration['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT],
                    );

                    return true;
                }))
                ->willReturn($connection);
            $factory = new LaravelMySqlServerConnectionFactory(
                $databaseManager,
                config(),
                new TenantConnectionConfigurationFactory(config()),
            );

            $server = $factory->open($shop->validatedDatabaseConnection());
            $server->close();

            $this->assertSame([], array_filter(
                array_keys((array) config('database.connections')),
                static fn (string $name): bool => str_starts_with($name, 'tenant_mysql_server_'),
            ));
        } finally {
            @unlink($caFile);
        }
    }

    public function test_shared_connection_configuration_preserves_hostname_and_trusted_options(): void
    {
        $template = (array) config('database.tenant_connection_template');
        $template['options'] = [1001 => 'trusted-ca', 1002 => true];
        config()->set('database.tenant_connection_template', $template);
        $suffix = Str::lower((string) Str::ulid());
        $shop = Shop::registerForProvisioning(
            name: 'MySQL Configuration Contract Shop',
            slug: 'mysql-config-'.$suffix,
            databaseDriver: 'mysql',
            databaseName: 'tenant_'.$suffix,
            databaseHost: 'localhost',
            databaseUsername: 'provisioner',
            databasePassword: 'not-logged',
        );

        $configuration = (new TenantConnectionConfigurationFactory(config()))
            ->make($shop->validatedDatabaseConnection());

        $this->assertSame('localhost', $configuration['host']);
        $this->assertSame([1001 => 'trusted-ca', 1002 => true], $configuration['options']);
        $this->assertArrayNotHasKey('url', $configuration);
    }

    private function lease(): TenantProvisioningLease
    {
        return new class implements TenantProvisioningLease
        {
            public function heartbeat(): void {}
        };
    }
}
