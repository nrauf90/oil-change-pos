<?php

namespace App\Tenancy;

use App\Models\Central\Shop;
use App\Tenancy\Exceptions\TenantDatabaseAttestationFailed;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use PDO;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class TenantConnectionManager
{
    private const CONNECTION = 'tenant';

    private const NO_TENANT_PERMISSION_CACHE_KEY = 'spatie.permission.cache.no-tenant';

    /** @var array<string, mixed> */
    private readonly array $tenantConnectionTemplate;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly TenantContext $context,
        private readonly PermissionRegistrar $permissionRegistrar,
        private readonly AuthManager $auth,
    ) {
        $tenantConnectionTemplate = $this->config->get('database.connections.tenant');

        if (! is_array($tenantConnectionTemplate)) {
            throw new LogicException('The tenant database connection template is not configured.');
        }

        unset($tenantConnectionTemplate['url']);
        $this->tenantConnectionTemplate = $tenantConnectionTemplate;
        $this->config->set('database.tenant_connection_template', $tenantConnectionTemplate);
    }

    public function connect(#[\SensitiveParameter] Shop $shop): void
    {
        if ($this->context->initialized()) {
            if (! hash_equals($this->context->id(), (string) $shop->getKey())) {
                throw new LogicException('A different tenant is already initialized.');
            }

            return;
        }

        $this->clearRuntimeState();
        $validatedConnection = $shop->validatedDatabaseConnection();
        $target = $validatedConnection->target();

        try {
            if ($target->driver === 'mysql') {
                $this->reAttestMySqlEndpoints($target);
            }

            $configuration = array_replace(
                $this->tenantConnectionTemplate,
                $validatedConnection->connectionOverrides(),
            );
            unset($configuration['url']);

            $this->database->purge(self::CONNECTION);
            $this->config->set('database.connections.'.self::CONNECTION, $configuration);
            $connection = $this->database->reconnect(self::CONNECTION);
            $pdo = $connection->getPdo();

            if ($target->driver === 'sqlite') {
                $this->attestSqliteConnection($pdo, $target);
            } elseif ($target->driver === 'mysql') {
                $this->attestMySqlConnection($pdo, $target);
            } else {
                throw new TenantDatabaseAttestationFailed;
            }

            $this->attestTenantMarker($pdo, $shop, $target);
            Model::setConnectionResolver($this->database);
            $this->context->initialize($shop);
            $this->initializePermissionState($this->context->id());
            $this->auth->forgetGuards();
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
        Closure $operation,
    ): mixed {
        if ($this->context->initialized()) {
            if (! hash_equals($this->context->id(), (string) $shop->getKey())) {
                throw new LogicException('A different tenant is already initialized.');
            }

            return $operation();
        }

        $this->connect($shop);

        try {
            return $operation();
        } finally {
            $this->disconnect();
        }
    }

    private function clearRuntimeState(): void
    {
        $this->database->purge(self::CONNECTION);
        $connections = $this->config->get('database.connections');

        if (is_array($connections)) {
            unset($connections[self::CONNECTION]);
            $this->config->set('database.connections', $connections);
        }
        $this->context->clear();
        Model::setConnectionResolver($this->database);
        $this->auth->forgetGuards();
        $this->config->set('permission.cache.key', self::NO_TENANT_PERMISSION_CACHE_KEY);
        $this->permissionRegistrar->initializeCache();
    }

    private function initializePermissionState(string $shopId): void
    {
        $this->config->set('permission.cache.key', 'spatie.permission.cache.tenant.'.$shopId);
        $this->permissionRegistrar->initializeCache();
    }

    private function reAttestMySqlEndpoints(
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        if (! $target->hasCurrentMySqlEndpoints(resolve(DatabaseHostResolver::class))) {
            throw new TenantDatabaseAttestationFailed;
        }
    }

    private function attestSqliteConnection(
        PDO $pdo,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        if ($target->filesystemIdentity === null || ! $target->hasStableFilesystemIdentity) {
            throw new TenantDatabaseAttestationFailed;
        }

        $statement = $pdo->query('PRAGMA database_list');
        $databases = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        $mainDatabase = null;

        foreach ($databases as $database) {
            if (($database['name'] ?? null) === 'main' && is_string($database['file'] ?? null)) {
                $mainDatabase = $database['file'];
                break;
            }
        }

        $canonicalDatabase = is_string($mainDatabase) ? realpath($mainDatabase) : false;
        $expectedDatabase = realpath($target->database);

        if ($canonicalDatabase === false
            || $expectedDatabase === false
            || ! hash_equals($expectedDatabase, $canonicalDatabase)
            || ! hash_equals($target->filesystemIdentity, $this->filesystemIdentity($canonicalDatabase))) {
            throw new TenantDatabaseAttestationFailed;
        }
    }

    private function attestMySqlConnection(
        PDO $pdo,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        $statement = $pdo->query('SELECT DATABASE()');
        $database = $statement === false ? false : $statement->fetchColumn();

        if (! is_string($database) || ! hash_equals($target->database, $database)) {
            throw new TenantDatabaseAttestationFailed;
        }
    }

    private function attestTenantMarker(
        PDO $pdo,
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        $statement = $pdo->prepare(
            'SELECT shop_id, target_fingerprint FROM tenant_installations WHERE id = ? LIMIT 2',
        );
        $statement->execute([1]);
        $markers = $statement->fetchAll(PDO::FETCH_ASSOC);

        if (count($markers) !== 1
            || ! is_string($markers[0]['shop_id'] ?? null)
            || ! is_string($markers[0]['target_fingerprint'] ?? null)
            || ! hash_equals((string) $shop->getKey(), $markers[0]['shop_id'])
            || ! hash_equals($target->fingerprint, $markers[0]['target_fingerprint'])) {
            throw new TenantDatabaseAttestationFailed;
        }
    }

    private function filesystemIdentity(#[\SensitiveParameter] string $database): string
    {
        $metadata = @stat($database);

        if (! is_array($metadata)
            || ! is_int($metadata['dev'] ?? null)
            || ! is_int($metadata['ino'] ?? null)
            || ($metadata['dev'] === 0 && $metadata['ino'] === 0)) {
            throw new TenantDatabaseAttestationFailed;
        }

        return $metadata['dev'].':'.$metadata['ino'];
    }
}
