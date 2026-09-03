<?php

namespace App\Actions\Tenancy;

use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Exceptions\TenantDatabaseTargetConflict;
use App\Exceptions\TenantProvisioningException;
use App\Models\Central\Shop;
use App\Models\Central\ShopHealthSnapshot;
use App\Models\Central\ShopOwner;
use App\Models\User;
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\DatabaseTargetConfiguration;
use App\Tenancy\Migrations\TenantMigrationRunner;
use App\Tenancy\NormalizedDatabaseTarget;
use App\Tenancy\OpenedTenantDatabaseIdentityVerifier;
use App\Tenancy\Provisioning\DatabaseTenantProvisioningLease;
use App\Tenancy\Provisioning\TenantInstallationBootstrapper;
use App\Tenancy\Provisioning\TenantInstallationReason;
use App\Tenancy\Provisioning\TenantProvisioningLease;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantSlug;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseLock;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

final readonly class AdoptExistingDatabase
{
    private const MARKER_MIGRATION = '2026_09_02_042731_create_tenant_installations_table';

    private const LEGACY_MONOLITH_ONLY_MIGRATIONS = [
        '0001_01_01_000001_create_cache_table',
        '0001_01_01_000002_create_jobs_table',
    ];

    private const REQUIRED_TABLES = [
        'migrations',
        'users',
        'roles',
        'permissions',
        'model_has_roles',
        'modules',
        'items',
        'sales',
        'sale_items',
    ];

    private const LOCK_STORE = 'database';

    public function __construct(
        private ConfigRepository $config,
        private DatabaseManager $database,
        private CacheManager $cache,
        private DatabaseHostResolver $hostResolver,
        private OpenedTenantDatabaseIdentityVerifier $identityVerifier,
        private TenantInstallationBootstrapper $installationBootstrapper,
        private TenantConnectionManager $connectionManager,
        private TenantMigrationRunner $migrationRunner,
        private RecordShopLifecycleActivity $recordLifecycleActivity,
        private Filesystem $filesystem,
    ) {}

    /**
     * @return array{
     *     driver: 'mysql'|'sqlite',
     *     required_table_count: int,
     *     migration_status: 'current'|'marker-pending'|'migrations-pending',
     *     owner_username: string,
     *     adopted: bool,
     *     shop_id: string|null
     * }
     */
    public function handle(
        string $name,
        string $slug,
        string $ownerUsername,
        ?string $ownerName = null,
        ?string $ownerEmail = null,
        bool $force = false,
    ): array {
        [$name, $slug, $ownerUsername, $ownerName, $ownerEmail] = $this->validatedInput(
            $name,
            $slug,
            $ownerUsername,
            $ownerName,
            $ownerEmail,
        );
        $this->connectionManager->disconnect();

        try {
            $inspection = $this->inspectSource(
                $name,
                $slug,
                $ownerUsername,
                $ownerName,
                $ownerEmail,
            );

            if (! $force) {
                return $this->publicResult($inspection, false, null);
            }

            return $this->withAdoptionLock(
                $inspection['target']->fingerprint,
                function (TenantProvisioningLease $lease) use (
                    $inspection,
                    $name,
                    $slug,
                    $ownerUsername,
                    $ownerName,
                    $ownerEmail,
                ): array {
                    $lease->heartbeat();
                    $lockedInspection = $this->inspectSource(
                        $name,
                        $slug,
                        $ownerUsername,
                        $ownerName,
                        $ownerEmail,
                    );

                    if (! hash_equals(
                        $inspection['target']->fingerprint,
                        $lockedInspection['target']->fingerprint,
                    )) {
                        throw $this->failure(
                            'ADOPTION_TARGET_CHANGED',
                            'The database target changed during adoption validation.',
                        );
                    }

                    $shop = $this->reserveOrResume($lockedInspection, $lease);

                    try {
                        $lease->heartbeat();
                        $this->installationBootstrapper->ensureInstalled(
                            $shop,
                            TenantInstallationReason::ForcedAdoption,
                            $lease,
                        );
                        $freshShop = Shop::query()->whereKey($shop->getKey())->firstOrFail();
                        $this->connectionManager->within(
                            $freshShop,
                            function () use ($lockedInspection, $lease): void {
                                $lease->heartbeat();
                                $this->migrationRunner->runConnected($lease);
                                $lease->heartbeat();
                                $this->verifyConnectedDatabase($lockedInspection);
                                $lease->heartbeat();
                            },
                        );
                    } finally {
                        $this->connectionManager->disconnect();
                    }

                    $adoptedShop = $this->finalizeAdoption($shop, $lockedInspection, $lease);

                    return $this->publicResult($lockedInspection, true, (string) $adoptedShop->getKey());
                },
            );
        } finally {
            $this->connectionManager->disconnect();
        }
    }

    /**
     * @return array{
     *     configuration: array<string, mixed>,
     *     target: NormalizedDatabaseTarget,
     *     driver: 'mysql'|'sqlite',
     *     required_table_count: int,
     *     migration_status: 'current'|'marker-pending'|'migrations-pending',
     *     owner_id: int|string,
     *     owner_name: string,
     *     owner_username: string,
     *     owner_email: string|null,
     *     existing_shop_id: string|null,
     *     shop_name: string,
     *     shop_slug: string
     * }
     */
    private function inspectSource(
        string $name,
        string $slug,
        string $ownerUsername,
        ?string $ownerName,
        ?string $ownerEmail,
    ): array {
        try {
            $configuration = $this->sourceConfiguration();
            $target = $this->normalizedTarget($configuration);
            $this->assertSqliteSourceSafeForReadOnlyInspection($target);
            $sourceConnectionName = 'tenant_adoption_source_'.str_replace('-', '', (string) Str::uuid());
            $connections = $this->config->get('database.connections');

            if (! is_array($connections)) {
                throw $this->failure(
                    'ADOPTION_SOURCE_INVALID',
                    'The configured source database is unavailable.',
                );
            }

            $connections[$sourceConnectionName] = $this->sourceConnectionConfiguration(
                $configuration,
                $target,
            );
            $this->config->set('database.connections', $connections);
            $this->database->purge($sourceConnectionName);

            try {
                $connection = $this->database->connection($sourceConnectionName);
                $this->identityVerifier->openAndVerify($connection, $target);
                $this->assertRequiredTables($connection);
                $migrationStatus = $this->migrationStatus($connection);
                $owner = $this->validatedOwner($connection, $ownerUsername);
                $effectiveOwnerName = $ownerName ?? $owner['name'];
                $existingShop = $this->existingTargetShop($target);
                $this->assertAvailableOrResumable(
                    $existingShop,
                    $target,
                    $name,
                    $slug,
                    $effectiveOwnerName,
                    $ownerUsername,
                    $ownerEmail,
                );
                $this->assertMarkerState($connection, $existingShop, $migrationStatus);

                return [
                    'configuration' => $configuration,
                    'target' => $target,
                    'driver' => $target->driver,
                    'required_table_count' => count(self::REQUIRED_TABLES),
                    'migration_status' => $migrationStatus,
                    'owner_id' => $owner['id'],
                    'owner_name' => $effectiveOwnerName,
                    'owner_username' => $ownerUsername,
                    'owner_email' => $ownerEmail,
                    'existing_shop_id' => $existingShop?->getKey(),
                    'shop_name' => $name,
                    'shop_slug' => $slug,
                ];
            } finally {
                $this->removeConnection($sourceConnectionName);
            }
        } catch (TenantProvisioningException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure(
                'ADOPTION_SOURCE_INVALID',
                'The configured source database could not be validated.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function sourceConfiguration(): array
    {
        $sourceName = $this->config->get('database.default');

        if (! is_string($sourceName) || $sourceName === '' || $sourceName === 'tenant') {
            throw $this->failure(
                'ADOPTION_SOURCE_INVALID',
                'The configured source database is unavailable.',
            );
        }

        $configuration = $this->config->get('database.connections.'.$sourceName);

        if (! is_array($configuration)) {
            throw $this->failure(
                'ADOPTION_SOURCE_INVALID',
                'The configured source database is unavailable.',
            );
        }

        $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);

        if (($configuration['driver'] ?? null) === 'mariadb') {
            $configuration['driver'] = 'mysql';
        }

        unset($configuration['url'], $configuration['name']);

        return $configuration;
    }

    /** @param array<string, mixed> $configuration */
    private function normalizedTarget(
        #[\SensitiveParameter]
        array $configuration,
    ): NormalizedDatabaseTarget {
        $tenantDefaults = $this->config->get('database.tenant_connection_template');
        $centralConfiguration = $this->config->get('database.connections.central');
        $sqliteRoot = $this->config->get('database.tenant_sqlite_root');

        if (! is_array($tenantDefaults)
            || ! is_array($centralConfiguration)
            || ! is_string($sqliteRoot)
            || $sqliteRoot === '') {
            throw $this->failure(
                'ADOPTION_CONFIGURATION_INVALID',
                'Tenant adoption configuration is incomplete.',
            );
        }

        try {
            return NormalizedDatabaseTarget::forTenant(
                target: DatabaseTargetConfiguration::fromLaravelConfiguration($configuration),
                defaults: DatabaseTargetConfiguration::fromLaravelConfiguration($tenantDefaults),
                central: DatabaseTargetConfiguration::fromLaravelConfiguration($centralConfiguration),
                sqliteRoot: $sqliteRoot,
                hostResolver: $this->hostResolver,
            );
        } catch (TenantDatabaseTargetConflict) {
            throw $this->failure(
                'ADOPTION_CENTRAL_TARGET_REJECTED',
                'The central platform database cannot be adopted.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function sourceConnectionConfiguration(
        #[\SensitiveParameter]
        array $configuration,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): array {
        $configuration['driver'] = $target->driver;
        $configuration['database'] = $target->database;
        unset($configuration['url'], $configuration['name']);

        if ($target->driver === 'sqlite') {
            $options = is_array($configuration['options'] ?? null)
                ? $configuration['options']
                : [];
            $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
            $configuration['options'] = $options;
            unset(
                $configuration['pragmas'],
                $configuration['foreign_key_constraints'],
                $configuration['journal_mode'],
                $configuration['synchronous'],
            );
        }

        return $configuration;
    }

    private function assertSqliteSourceSafeForReadOnlyInspection(
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        if ($target->driver !== 'sqlite') {
            return;
        }

        foreach (['-journal', '-wal', '-shm'] as $suffix) {
            if (is_file($target->database.$suffix)) {
                throw $this->failure(
                    'ADOPTION_SQLITE_WAL_UNSAFE',
                    'The SQLite source must be offline, checkpointed, and free of journal sidecar files.',
                );
            }
        }

        $handle = @fopen($target->database, 'rb');

        if (! is_resource($handle)) {
            throw $this->failure(
                'ADOPTION_SOURCE_INVALID',
                'The configured source database could not be read.',
            );
        }

        try {
            $header = fread($handle, 20);
        } finally {
            fclose($handle);
        }

        if (is_string($header)
            && strlen($header) >= 20
            && (ord($header[18]) === 2 || ord($header[19]) === 2)) {
            throw $this->failure(
                'ADOPTION_SQLITE_WAL_UNSAFE',
                'The SQLite source must use rollback-journal mode before adoption.',
            );
        }
    }

    private function assertRequiredTables(Connection $connection): void
    {
        $tableNames = $connection->getSchemaBuilder()->getTableListing(schemaQualified: false);
        $missing = array_values(array_diff(self::REQUIRED_TABLES, $tableNames));

        if ($missing !== []) {
            throw $this->failure(
                'ADOPTION_REQUIRED_TABLES_MISSING',
                'The source database is missing required tenant tables.',
            );
        }
    }

    /** @return 'current'|'marker-pending'|'migrations-pending' */
    private function migrationStatus(Connection $connection): string
    {
        $canonical = $this->canonicalMigrations();
        $ran = $connection->table('migrations')
            ->orderBy('migration')
            ->pluck('migration')
            ->all();

        if (count($ran) !== count(array_unique($ran))) {
            throw $this->failure(
                'ADOPTION_MIGRATION_HISTORY_INVALID',
                'The source migration history is inconsistent.',
            );
        }

        foreach ($ran as $migration) {
            if (! is_string($migration)) {
                throw $this->failure(
                    'ADOPTION_MIGRATION_HISTORY_INVALID',
                    'The source migration history is inconsistent.',
                );
            }
        }

        if (array_diff($ran, $canonical, self::LEGACY_MONOLITH_ONLY_MIGRATIONS) !== []) {
            throw $this->failure(
                'ADOPTION_UNKNOWN_MIGRATIONS',
                'The source database contains unknown tenant migrations.',
            );
        }

        $ranTenantMigrations = array_values(array_diff(
            $ran,
            self::LEGACY_MONOLITH_ONLY_MIGRATIONS,
        ));
        $expectedRanTenantMigrations = array_slice($canonical, 0, count($ranTenantMigrations));

        if ($ranTenantMigrations !== $expectedRanTenantMigrations) {
            throw $this->failure(
                'ADOPTION_MIGRATIONS_PENDING',
                'The source database has pending tenant migrations.',
            );
        }

        $pending = array_slice($canonical, count($ranTenantMigrations));

        if ($pending === []) {
            return 'current';
        }

        if ($pending[0] === self::MARKER_MIGRATION) {
            return 'marker-pending';
        }

        if (in_array(self::MARKER_MIGRATION, $ranTenantMigrations, true)) {
            return 'migrations-pending';
        }

        throw $this->failure(
            'ADOPTION_MIGRATIONS_PENDING',
            'The source database has pending tenant migrations.',
        );
    }

    /** @return list<string> */
    private function canonicalMigrations(): array
    {
        $paths = $this->filesystem->glob(database_path('migrations/tenant/*.php'));
        $migrations = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $paths,
        );
        sort($migrations, SORT_STRING);

        if (! in_array(self::MARKER_MIGRATION, $migrations, true)) {
            throw $this->failure(
                'ADOPTION_CONFIGURATION_INVALID',
                'The canonical tenant marker migration is unavailable.',
            );
        }

        return $migrations;
    }

    /** @return array{id: int|string, name: string} */
    private function validatedOwner(Connection $connection, string $username): array
    {
        $users = $connection->table('users')
            ->where('username', $username)
            ->limit(2)
            ->get(['id', 'name', 'is_active']);

        if ($users->count() !== 1) {
            throw $this->failure(
                'ADOPTION_OWNER_INVALID',
                'The requested tenant owner is unavailable.',
            );
        }

        $user = $users->first();
        $userId = is_object($user) ? ($user->id ?? null) : null;
        $userName = is_object($user) ? ($user->name ?? null) : null;
        $isActive = is_object($user) ? ($user->is_active ?? null) : null;

        if ((! is_int($userId) && ! is_string($userId))
            || ! is_string($userName)
            || trim($userName) === ''
            || ! (bool) $isActive) {
            throw $this->failure(
                'ADOPTION_OWNER_INVALID',
                'The requested tenant owner is unavailable.',
            );
        }

        $roles = $connection->table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->limit(2)
            ->pluck('id');

        if ($roles->count() !== 1
            || ! $connection->table('model_has_roles')
                ->where('role_id', $roles->first())
                ->where('model_type', User::class)
                ->where('model_id', $userId)
                ->exists()) {
            throw $this->failure(
                'ADOPTION_OWNER_NOT_ADMIN',
                'The requested tenant owner is not an active web administrator.',
            );
        }

        return ['id' => $userId, 'name' => $userName];
    }

    private function existingTargetShop(
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): ?Shop {
        $claims = $target->claimFingerprints();
        $shops = Shop::withTrashed()
            ->where(static function (Builder $query) use ($claims): void {
                $query->whereIn('database_target_fingerprint', $claims)
                    ->orWhereIn('database_target_locator_fingerprint', $claims)
                    ->orWhereHas(
                        'databaseTargetClaims',
                        static fn (Builder $claimQuery): Builder => $claimQuery->whereIn(
                            'fingerprint',
                            $claims,
                        ),
                    );
            })
            ->limit(2)
            ->get();

        if ($shops->count() > 1) {
            throw $this->failure(
                'ADOPTION_TARGET_ASSIGNED',
                'The source database target is already assigned.',
            );
        }

        return $shops->first();
    }

    private function assertAvailableOrResumable(
        ?Shop $shop,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
        string $name,
        string $slug,
        string $ownerName,
        string $ownerUsername,
        ?string $ownerEmail,
    ): void {
        if (! $shop instanceof Shop) {
            if (Shop::withTrashed()->where('slug', $slug)->exists()) {
                throw $this->failure(
                    'ADOPTION_SLUG_ASSIGNED',
                    'The requested shop slug is already assigned.',
                );
            }

            return;
        }

        if ($shop->trashed()) {
            throw $this->failure(
                'ADOPTION_TARGET_ASSIGNED',
                'The source database target is already assigned.',
            );
        }

        $adopted = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::ExistingDatabaseAdopted->value)
            ->exists();

        if ($shop->status === ShopStatus::Active && $adopted) {
            throw $this->failure(
                'ADOPTION_ALREADY_COMPLETED',
                'The source database was already adopted.',
            );
        }

        $owner = $shop->owner()->first();

        if ($shop->status !== ShopStatus::Provisioning
            || $shop->name !== $name
            || $shop->slug !== $slug
            || ! $owner instanceof ShopOwner
            || $owner->name !== $ownerName
            || $owner->username !== $ownerUsername
            || $owner->email !== $ownerEmail
            || ! $owner->is_active
            || ! $this->hasForcedAdoptionReceipt($shop, $target)) {
            throw $this->failure(
                'ADOPTION_TARGET_ASSIGNED',
                'The source database target is already assigned.',
            );
        }
    }

    private function hasForcedAdoptionReceipt(
        Shop $shop,
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): bool {
        $activities = $shop->lifecycleActivities()
            ->where('event', ShopLifecycleEvent::TenantInstallationAuthorized->value)
            ->latest('occurred_at')
            ->get();

        foreach ($activities as $activity) {
            $metadata = $activity->metadata;

            if (is_array($metadata)
                && ($metadata['database_driver'] ?? null) === $target->driver
                && ($metadata['reason_code'] ?? null) === TenantInstallationReason::ForcedAdoption->value
                && is_string($metadata['target_fingerprint'] ?? null)
                && hash_equals($target->fingerprint, $metadata['target_fingerprint'])) {
                return true;
            }
        }

        return false;
    }

    /** @param 'current'|'marker-pending'|'migrations-pending' $migrationStatus */
    private function assertMarkerState(
        Connection $connection,
        ?Shop $existingShop,
        string $migrationStatus,
    ): void {
        if (! $existingShop instanceof Shop) {
            if ($migrationStatus !== 'marker-pending'
                || $connection->getSchemaBuilder()->hasTable('tenant_installations')) {
                throw $this->failure(
                    'ADOPTION_MARKER_CONFLICT',
                    'The source tenant marker state is not eligible for adoption.',
                );
            }

            return;
        }

        if ($migrationStatus !== 'marker-pending'
            && ! $connection->getSchemaBuilder()->hasTable('tenant_installations')) {
            throw $this->failure(
                'ADOPTION_MARKER_CONFLICT',
                'The source tenant marker state is inconsistent.',
            );
        }
    }

    /**
     * @param array{
     *     configuration: array<string, mixed>,
     *     target: NormalizedDatabaseTarget,
     *     owner_name: string,
     *     owner_username: string,
     *     owner_email: string|null,
     *     existing_shop_id: string|null,
     *     shop_name: string,
     *     shop_slug: string
     * } $inspection
     */
    private function reserveOrResume(
        #[\SensitiveParameter]
        array $inspection,
        TenantProvisioningLease $lease,
    ): Shop {
        try {
            return DB::connection('central')->transaction(function () use ($inspection, $lease): Shop {
                $lease->heartbeat();
                $existingShopId = $inspection['existing_shop_id'];

                if (is_string($existingShopId)) {
                    $shop = Shop::query()->whereKey($existingShopId)->lockForUpdate()->first();

                    if (! $shop instanceof Shop) {
                        throw $this->failure(
                            'ADOPTION_TARGET_ASSIGNED',
                            'The source database target is no longer resumable.',
                        );
                    }

                    $this->assertAvailableOrResumable(
                        $shop,
                        $inspection['target'],
                        $inspection['shop_name'],
                        $inspection['shop_slug'],
                        $inspection['owner_name'],
                        $inspection['owner_username'],
                        $inspection['owner_email'],
                    );

                    return $shop;
                }

                $configuration = $inspection['configuration'];
                $target = $inspection['target'];
                $shop = Shop::registerForProvisioning(
                    name: $inspection['shop_name'],
                    slug: $inspection['shop_slug'],
                    databaseDriver: $target->driver,
                    databaseName: $target->database,
                    databaseHost: $target->host,
                    databasePort: $target->port,
                    databaseUsername: $this->nullableConfigurationString($configuration, 'username'),
                    databasePassword: $this->nullableConfigurationString($configuration, 'password'),
                    databaseSocket: $target->socket,
                );
                ShopOwner::query()->create([
                    'shop_id' => $shop->getKey(),
                    'name' => $inspection['owner_name'],
                    'username' => $inspection['owner_username'],
                    'email' => $inspection['owner_email'],
                    'is_active' => true,
                ]);
                $lease->heartbeat();
                $this->recordLifecycleActivity->handle(
                    $shop,
                    ShopLifecycleEvent::TenantInstallationAuthorized,
                    metadata: [
                        'database_driver' => $target->driver,
                        'reason_code' => TenantInstallationReason::ForcedAdoption->value,
                        'target_fingerprint' => $target->fingerprint,
                    ],
                );

                return $shop;
            });
        } catch (TenantProvisioningException $exception) {
            throw $exception;
        } catch (TenantDatabaseTargetConflict|UniqueConstraintViolationException) {
            throw $this->failure(
                'ADOPTION_CONFLICT',
                'The database adoption lost a concurrent registration race.',
            );
        } catch (Throwable) {
            throw $this->failure(
                'ADOPTION_REGISTRATION_FAILED',
                'The source database could not be reserved for adoption.',
            );
        }
    }

    /**
     * @param array{
     *     owner_id: int|string,
     *     owner_username: string
     * } $inspection
     */
    private function verifyConnectedDatabase(
        #[\SensitiveParameter]
        array $inspection,
    ): void {
        $connection = $this->database->connection('tenant');
        $this->assertRequiredTables($connection);

        if ($this->migrationStatus($connection) !== 'current') {
            throw $this->failure(
                'ADOPTION_MIGRATIONS_PENDING',
                'The adopted database still has pending tenant migrations.',
            );
        }

        $owner = $this->validatedOwner($connection, $inspection['owner_username']);

        if ((string) $owner['id'] !== (string) $inspection['owner_id']) {
            throw $this->failure(
                'ADOPTION_OWNER_CHANGED',
                'The requested tenant owner changed during adoption.',
            );
        }
    }

    /**
     * @param  array{driver: string, required_table_count: int}  $inspection
     */
    private function finalizeAdoption(
        Shop $shop,
        #[\SensitiveParameter]
        array $inspection,
        TenantProvisioningLease $lease,
    ): Shop {
        try {
            return DB::connection('central')->transaction(function () use (
                $shop,
                $inspection,
                $lease,
            ): Shop {
                $freshShop = Shop::query()->whereKey($shop->getKey())->lockForUpdate()->first();

                if (! $freshShop instanceof Shop || $freshShop->status !== ShopStatus::Provisioning) {
                    throw $this->failure(
                        'ADOPTION_STATE_CHANGED',
                        'The shop adoption state changed before activation.',
                    );
                }

                $lease->heartbeat();
                ShopHealthSnapshot::query()->updateOrCreate(
                    ['shop_id' => $freshShop->getKey()],
                    [
                        'last_successful_connection_at' => now(),
                        'migration_status' => 'current',
                        'last_activity_at' => now(),
                        'summary' => null,
                    ],
                );
                $freshShop->markActive();
                $lease->heartbeat();
                $this->recordLifecycleActivity->handle(
                    $freshShop,
                    ShopLifecycleEvent::ExistingDatabaseAdopted,
                    metadata: [
                        'database_driver' => $inspection['driver'],
                        'table_count' => $inspection['required_table_count'],
                        'owner_linked' => true,
                    ],
                );

                return $freshShop->fresh();
            });
        } catch (TenantProvisioningException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure(
                'ADOPTION_FINALIZATION_FAILED',
                'The adopted shop could not be activated.',
            );
        }
    }

    /**
     * @template TResult
     *
     * @param  Closure(TenantProvisioningLease): TResult  $operation
     * @return TResult
     */
    private function withAdoptionLock(string $fingerprint, Closure $operation): mixed
    {
        $this->assertCentralLockStore();
        $seconds = $this->lockSeconds();
        $lock = $this->cache->store(self::LOCK_STORE)->lock(
            'tenant-adopt:'.$fingerprint,
            $seconds,
        );

        if (! $lock instanceof DatabaseLock) {
            throw $this->failure(
                'ADOPTION_LOCK_MISCONFIGURED',
                'Adoption requires a renewable central database lock.',
            );
        }

        try {
            if (! $lock->get()) {
                throw $this->failure(
                    'ADOPTION_BUSY',
                    'Another adoption attempt is already running for this target.',
                );
            }
        } catch (TenantProvisioningException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->failure(
                'ADOPTION_LOCK_UNAVAILABLE',
                'The adoption lock is unavailable. Retry later.',
            );
        }

        $lease = new DatabaseTenantProvisioningLease($lock, $seconds);

        try {
            return $operation($lease);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
            }
        }
    }

    private function assertCentralLockStore(): void
    {
        if ($this->config->get('cache.stores.'.self::LOCK_STORE.'.driver') !== 'database'
            || $this->config->get('cache.stores.'.self::LOCK_STORE.'.connection') !== 'central'
            || $this->config->get('cache.stores.'.self::LOCK_STORE.'.lock_connection') !== 'central') {
            throw $this->failure(
                'ADOPTION_LOCK_MISCONFIGURED',
                'Adoption requires the shared central database lock store.',
            );
        }
    }

    private function lockSeconds(): int
    {
        $configured = $this->config->get('database.tenant_provisioning_lock_seconds', 900);
        $seconds = is_int($configured)
            ? $configured
            : (is_string($configured) && ctype_digit($configured) ? (int) $configured : 0);

        if ($seconds < 1) {
            throw $this->failure(
                'ADOPTION_LOCK_MISCONFIGURED',
                'Adoption requires a positive central database lock duration.',
            );
        }

        return $seconds;
    }

    private function removeConnection(string $connectionName): void
    {
        try {
            $this->database->purge($connectionName);
        } catch (Throwable) {
        }

        $connections = $this->config->get('database.connections');

        if (is_array($connections)) {
            unset($connections[$connectionName]);
            $this->config->set('database.connections', $connections);
        }
    }

    /**
     * @return array{string, string, string, string|null, string|null}
     */
    private function validatedInput(
        string $name,
        string $slug,
        string $ownerUsername,
        ?string $ownerName,
        ?string $ownerEmail,
    ): array {
        $name = trim($name);
        $slug = trim($slug);
        $ownerUsername = trim($ownerUsername);
        $ownerName = $ownerName === null ? null : trim($ownerName);
        $ownerEmail = $ownerEmail === null ? null : trim($ownerEmail);

        if (! $this->isSafeLabel($name)
            || ! TenantSlug::isValid($slug)
            || ! $this->isSafeLabel($ownerUsername)
            || ($ownerName !== null && ! $this->isSafeLabel($ownerName))
            || ($ownerEmail !== null
                && (strlen($ownerEmail) > 255
                    || filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false))) {
            throw $this->failure(
                'ADOPTION_INPUT_INVALID',
                'The adoption options are incomplete or invalid.',
            );
        }

        return [$name, $slug, $ownerUsername, $ownerName, $ownerEmail];
    }

    private function isSafeLabel(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /** @param array<string, mixed> $configuration */
    private function nullableConfigurationString(
        #[\SensitiveParameter]
        array $configuration,
        string $key,
    ): ?string {
        $value = $configuration[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        throw $this->failure(
            'ADOPTION_SOURCE_INVALID',
            'The configured source database credentials are malformed.',
        );
    }

    /**
     * @param array{
     *     driver: 'mysql'|'sqlite',
     *     required_table_count: int,
     *     migration_status: 'current'|'marker-pending'|'migrations-pending',
     *     owner_username: string
     * } $inspection
     * @return array{
     *     driver: 'mysql'|'sqlite',
     *     required_table_count: int,
     *     migration_status: 'current'|'marker-pending'|'migrations-pending',
     *     owner_username: string,
     *     adopted: bool,
     *     shop_id: string|null
     * }
     */
    private function publicResult(
        #[\SensitiveParameter]
        array $inspection,
        bool $adopted,
        ?string $shopId,
    ): array {
        return [
            'driver' => $inspection['driver'],
            'required_table_count' => $inspection['required_table_count'],
            'migration_status' => $adopted ? 'current' : $inspection['migration_status'],
            'owner_username' => $inspection['owner_username'],
            'adopted' => $adopted,
            'shop_id' => $shopId,
        ];
    }

    private function failure(string $errorCode, string $message): TenantProvisioningException
    {
        return TenantProvisioningException::safe('adoption', $errorCode, $message);
    }
}
