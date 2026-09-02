<?php

namespace App\Tenancy;

use App\Enums\ShopStatus;
use App\Models\Central\Shop;
use App\Modules\ModuleRegistry;
use App\Tenancy\Exceptions\TenantDatabaseAttestationFailed;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use PDO;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TenantConnectionManager
{
    private const CONNECTION = 'tenant';

    private const NO_TENANT_PERMISSION_CACHE_KEY = 'spatie.permission.cache.no-tenant';

    /** @var array<string, mixed> */
    private readonly array $tenantConnectionTemplate;

    private ?TenantConnectionLease $activeLease = null;

    private ?Connection $activeConnection = null;

    private ?PDO $activePdo = null;

    private ?Shop $activeShop = null;

    private ?Shop $pendingShop = null;

    private ?ValidatedTenantConnection $pendingSnapshot = null;

    private bool $handlingConnectionEvent = false;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly TenantRuntimeState $runtimeState,
        private readonly PermissionRegistrar $permissionRegistrar,
        private readonly AuthManager $auth,
        private readonly TenantDatabaseAttestor $attestor,
        private readonly ModuleRegistry $moduleRegistry,
    ) {
        $tenantConnectionTemplate = $this->config->get('database.tenant_connection_template');

        if (! is_array($tenantConnectionTemplate)) {
            throw new LogicException('The tenant database connection template is not configured.');
        }

        unset($tenantConnectionTemplate['url'], $tenantConnectionTemplate['name']);
        $this->tenantConnectionTemplate = $tenantConnectionTemplate;
    }

    public function connect(
        #[\SensitiveParameter]
        Shop $shop,
        bool $requireActiveShop = false,
    ): void {
        $shopId = $shop->getKey();

        if (! $shop->exists || ! is_string($shopId) || $shopId === '') {
            throw new LogicException('Tenant connections require a persisted shop.');
        }

        if ($this->activeLease !== null) {
            if ($this->activeShop === null
                || ! hash_equals((string) $this->activeShop->getKey(), $shopId)) {
                throw new LogicException('A different tenant is already initialized.');
            }

            if ($this->hasLiveAttestedConnection($this->activeLease)) {
                if ($requireActiveShop) {
                    $this->revalidateActiveShop($shop);
                }

                return;
            }
        }

        $this->clearRuntimeState();

        try {
            $snapshot = $this->validatedConnectionSnapshot($shop, $requireActiveShop);
            $this->pendingShop = clone $shop;
            $this->pendingSnapshot = $snapshot;
            $this->config->set(
                'database.connections.'.self::CONNECTION,
                $this->connectionConfiguration($snapshot),
            );
            $this->database->purge(self::CONNECTION);
            $this->database->connection(self::CONNECTION);

            if ($this->activeLease === null
                || $this->activeShop === null
                || ! $this->runtimeState->isOwnedBy($this->activeLease)) {
                throw new TenantDatabaseAttestationFailed;
            }
        } catch (Throwable) {
            $this->clearRuntimeState();

            throw new TenantDatabaseAttestationFailed;
        }
    }

    public function disconnect(): void
    {
        $this->clearRuntimeState();
    }

    public function within(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        Closure $operation,
        bool $requireActiveShop = false,
    ): mixed {
        if ($this->activeLease !== null) {
            if ($this->activeShop === null
                || ! hash_equals((string) $this->activeShop->getKey(), (string) $shop->getKey())) {
                throw new LogicException('A different tenant is already initialized.');
            }

            if (! $this->hasLiveAttestedConnection($this->activeLease)) {
                throw new LogicException('The active tenant connection is no longer attested.');
            }

            if ($requireActiveShop) {
                $this->revalidateActiveShop($shop);
            }

            return $operation();
        }

        $this->connect($shop, $requireActiveShop);

        try {
            return $operation();
        } finally {
            $this->disconnect();
        }
    }

    public function attestEstablishedConnection(Connection $connection): void
    {
        if ($connection->getName() !== self::CONNECTION) {
            return;
        }

        if ($this->handlingConnectionEvent) {
            $this->failClosed();

            throw new TenantDatabaseAttestationFailed;
        }

        $this->handlingConnectionEvent = true;

        try {
            [$shop, $snapshot] = $this->connectionAttestationSubject();
            $this->runtimeState->deactivate();
            $pdo = $this->attestor->attest(
                $connection,
                $shop,
                $snapshot,
                $this->connectionConfiguration($snapshot),
            );

            $this->activate($shop, $connection, $pdo);
        } catch (Throwable) {
            $this->failClosed();

            throw new TenantDatabaseAttestationFailed;
        } finally {
            $this->handlingConnectionEvent = false;
        }
    }

    /** @return array{Shop, ValidatedTenantConnection} */
    private function connectionAttestationSubject(): array
    {
        if ($this->pendingShop !== null && $this->pendingSnapshot !== null) {
            return [$this->pendingShop, $this->pendingSnapshot];
        }

        if ($this->activeLease === null
            || $this->activeShop === null
            || ! $this->runtimeState->isOwnedBy($this->activeLease)) {
            throw new TenantDatabaseAttestationFailed;
        }

        $snapshot = $this->activeShop->validatedDatabaseConnection();

        return [$this->activeShop, $snapshot];
    }

    private function revalidateActiveShop(#[\SensitiveParameter] Shop $shop): void
    {
        try {
            $this->validatedConnectionSnapshot($shop, true);
        } catch (Throwable) {
            $this->clearRuntimeState();

            throw new TenantDatabaseAttestationFailed;
        }
    }

    private function validatedConnectionSnapshot(
        #[\SensitiveParameter]
        Shop $shop,
        bool $requireActiveShop,
    ): ValidatedTenantConnection {
        if ($shop::class !== Shop::class) {
            throw new TenantDatabaseAttestationFailed;
        }

        $snapshot = $shop->validatedDatabaseConnection();

        if ($requireActiveShop && $shop->status !== ShopStatus::Active) {
            throw new TenantDatabaseAttestationFailed;
        }

        return $snapshot;
    }

    private function activate(
        #[\SensitiveParameter]
        Shop $shop,
        Connection $connection,
        PDO $pdo,
    ): void {
        $lease = new TenantConnectionLease;
        $this->activeLease = $lease;
        $this->activeConnection = $connection;
        $this->activePdo = $pdo;
        $this->activeShop = clone $shop;
        $this->pendingShop = null;
        $this->pendingSnapshot = null;
        $this->runtimeState->activate($shop, $lease);
        $this->redactConnectionCredentials($connection);
        $connection->setReconnector(static function (#[\SensitiveParameter] Connection $reconnecting) use ($lease): void {
            resolve(self::class)->reconnectOwnedConnection($reconnecting, $lease);
        });
        Model::setConnectionResolver($this->database);
        $this->moduleRegistry->flush();
        $this->initializePermissionState((string) $shop->getKey());
        $this->auth->forgetGuards();
    }

    private function reconnectOwnedConnection(
        #[\SensitiveParameter]
        Connection $connection,
        #[\SensitiveParameter]
        TenantConnectionLease $lease,
    ): void {
        $registeredConnection = $this->database->getConnections()[self::CONNECTION] ?? null;

        if ($this->activeLease === null
            || ! $lease->owns($this->activeLease)
            || ! $this->runtimeState->isOwnedBy($lease)
            || $this->activeConnection !== $connection
            || $registeredConnection !== $connection) {
            throw new LogicException('A stale tenant connection cannot be reconnected.');
        }

        $freshConnection = $this->database->reconnect(self::CONNECTION);
        $connection->setPdo($freshConnection->getRawPdo());
        $connection->setReadPdo($freshConnection->getRawReadPdo());
    }

    private function hasLiveAttestedConnection(TenantConnectionLease $lease): bool
    {
        $registeredConnection = $this->database->getConnections()[self::CONNECTION] ?? null;

        return $this->runtimeState->isOwnedBy($lease)
            && $this->activeConnection !== null
            && $registeredConnection === $this->activeConnection
            && $this->activeConnection->getRawPdo() === $this->activePdo
            && $this->activePdo instanceof PDO
            && $this->config->has('database.connections.'.self::CONNECTION);
    }

    /** @return array<string, mixed> */
    private function connectionConfiguration(
        #[\SensitiveParameter]
        ValidatedTenantConnection $snapshot,
    ): array {
        $target = $snapshot->target();
        $configuration = array_replace(
            $this->tenantConnectionTemplate,
            $snapshot->connectionOverrides(),
        );
        unset($configuration['url'], $configuration['name']);
        $configuration['driver'] = $target->driver;
        $configuration['database'] = $target->database;

        if ($target->effectiveSocket !== null) {
            unset($configuration['host'], $configuration['port']);
            $configuration['unix_socket'] = $target->effectiveSocket;
        } elseif ($target->driver === 'mysql') {
            unset($configuration['unix_socket']);
            $configuration['host'] = $target->effectiveHost;
            $configuration['port'] = $target->effectivePort;
        } else {
            unset($configuration['host'], $configuration['port'], $configuration['unix_socket']);
        }

        return $configuration;
    }

    private function failClosed(): void
    {
        $this->clearRuntimeState();
    }

    private function clearRuntimeState(): void
    {
        $connections = [];
        $registeredConnection = $this->database->getConnections()[self::CONNECTION] ?? null;

        foreach ([$registeredConnection, $this->activeConnection] as $connection) {
            if ($connection instanceof Connection) {
                $connections[spl_object_id($connection)] = $connection;
            }
        }

        $this->runtimeState->deactivate();
        $this->activeLease = null;
        $this->activeConnection = null;
        $this->activePdo = null;
        $this->activeShop = null;
        $this->pendingShop = null;
        $this->pendingSnapshot = null;
        $this->config->set('permission.cache.key', self::NO_TENANT_PERMISSION_CACHE_KEY);
        $this->removeTenantConfiguration();

        foreach ($connections as $connection) {
            $this->redactConnectionCredentials($connection);
            $connection->setReconnector(
                static function (#[\SensitiveParameter] Connection $staleConnection): never {
                    throw new LogicException('A stale tenant connection cannot be reconnected.');
                },
            );
            $connection->setPdo(null)->setReadPdo(null)->setDirectPdo(null);
        }

        $this->attemptCleanup(fn (): mixed => $this->database->purge(self::CONNECTION));

        if (array_key_exists(self::CONNECTION, $this->database->getConnections())) {
            foreach ($connections as $connection) {
                $connection->unsetTransactionManager();
            }

            $this->attemptCleanup(fn (): mixed => $this->database->purge(self::CONNECTION));
        }

        $this->attemptCleanup(fn (): mixed => Model::setConnectionResolver($this->database));
        $this->attemptCleanup(fn (): mixed => $this->moduleRegistry->flush());
        $this->attemptCleanup(fn (): mixed => $this->auth->forgetGuards());
        $this->attemptCleanup(fn (): mixed => $this->permissionRegistrar->clearPermissionsCollection());
        $this->attemptCleanup(fn (): mixed => $this->permissionRegistrar->initializeCache());
    }

    private function removeTenantConfiguration(): void
    {
        $connections = $this->config->get('database.connections');

        if (is_array($connections)) {
            unset($connections[self::CONNECTION]);
            $this->config->set('database.connections', $connections);
        }
    }

    private function initializePermissionState(string $shopId): void
    {
        $this->config->set('permission.cache.key', 'spatie.permission.cache.tenant.'.$shopId);
        $this->permissionRegistrar->initializeCache();
    }

    private function attemptCleanup(#[\SensitiveParameter] Closure $operation): void
    {
        try {
            $operation();
        } catch (Throwable) {
        }
    }

    private function redactConnectionCredentials(#[\SensitiveParameter] Connection $connection): void
    {
        $configurationProperty = new \ReflectionProperty(Connection::class, 'config');
        $configuration = $configurationProperty->getValue($connection);

        if (! is_array($configuration)) {
            return;
        }

        foreach (['username', 'password', 'url'] as $credential) {
            if (array_key_exists($credential, $configuration)) {
                $configuration[$credential] = '[redacted]';
            }
        }

        $configurationProperty->setValue($connection, $configuration);
    }
}
