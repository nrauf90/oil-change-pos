<?php

namespace App\Models\Central;

use App\Enums\ShopStatus;
use App\Exceptions\TenantDatabaseTargetConflict;
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\DatabaseTargetConfiguration;
use App\Tenancy\NormalizedDatabaseTarget;
use App\Tenancy\SqliteDatabaseIdentitySnapshot;
use App\Tenancy\ValidatedTenantConnection;
use Database\Factories\Central\ShopFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

class Shop extends CentralModel
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    private const DATABASE_TARGET_ATTRIBUTES = [
        'slug',
        'database_driver',
        'database_name',
        'database_target_fingerprint',
        'database_target_locator_fingerprint',
        'database_host',
        'database_port',
        'database_socket',
        'database_username',
        'database_password',
    ];

    private const DATABASE_TARGET_UNIQUE_INDEX = 'shops_database_target_fingerprint_unique';

    private const DATABASE_TARGET_LOCATOR_UNIQUE_INDEX = 'shops_database_target_locator_fingerprint_unique';

    private const DATABASE_TARGET_CLAIM_UNIQUE_INDEX = 'shop_database_target_claims_fingerprint_unique';

    protected $fillable = ['name', 'timezone', 'currency'];

    protected $hidden = [
        'database_target_fingerprint',
        'database_target_locator_fingerprint',
        'database_host',
        'database_port',
        'database_socket',
        'database_username',
        'database_password',
    ];

    private bool $allowsLifecycleTransition = false;

    private bool $allowsProvisioningTargetChange = false;

    /** @var list<string> */
    private array $pendingDatabaseTargetClaimFingerprints = [];

    private ?NormalizedDatabaseTarget $prevalidatedDatabaseTarget = null;

    protected function casts(): array
    {
        return [
            'status' => ShopStatus::class,
            'database_host' => 'encrypted:json',
            'database_port' => 'encrypted',
            'database_socket' => 'encrypted',
            'database_username' => 'encrypted',
            'database_password' => 'encrypted',
            'provisioning_failed_at' => 'datetime',
            'provisioned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $shop): void {
            if (! $shop->exists) {
                $target = $shop->prevalidatedDatabaseTarget ?? $shop->normalizedDatabaseTarget();
                $shop->applyNormalizedDatabaseTarget($target);
                $shop->assertDatabaseTargetIsAvailable($target);
            }

            if ($shop->exists && $shop->isDirty('status') && ! $shop->allowsLifecycleTransition) {
                throw new LogicException('Shop status must be changed through a lifecycle method.');
            }

            if ($shop->exists
                && $shop->isDirty(self::DATABASE_TARGET_ATTRIBUTES)
                && ! $shop->allowsProvisioningTargetChange) {
                throw new LogicException('Shop database targets must be changed through updateProvisioningTarget().');
            }
        });

        static::created(function (self $shop): void {
            $shop->replaceDatabaseTargetClaims($shop->pendingDatabaseTargetClaimFingerprints);
        });
    }

    public static function registerForProvisioning(
        string $name,
        string $slug,
        #[\SensitiveParameter]
        string $databaseDriver,
        #[\SensitiveParameter]
        string $databaseName,
        #[\SensitiveParameter]
        array|string|null $databaseHost = null,
        #[\SensitiveParameter]
        ?int $databasePort = null,
        #[\SensitiveParameter]
        ?string $databaseUsername = null,
        #[\SensitiveParameter]
        ?string $databasePassword = null,
        #[\SensitiveParameter]
        ?string $databaseSocket = null,
        string $timezone = 'Asia/Karachi',
        string $currency = 'PKR',
    ): self {
        $target = self::normalizeDatabaseTarget(
            databaseDriver: $databaseDriver,
            databaseName: $databaseName,
            databaseHost: $databaseHost,
            databasePort: $databasePort,
            databaseSocket: $databaseSocket,
        );
        $shop = new self;
        $shop->attributes['name'] = $name;
        $shop->attributes['slug'] = $slug;
        $shop->attributes['status'] = ShopStatus::Provisioning->value;
        $shop->attributes['timezone'] = $timezone;
        $shop->attributes['currency'] = $currency;
        $shop->prevalidatedDatabaseTarget = $target;
        $shop->applyNormalizedDatabaseTarget($target);
        $shop->setProvisioningCredentials($databaseUsername, $databasePassword);
        $connection = $shop->getConnection();
        $startingTransactionLevel = $connection->transactionLevel();
        $connection->beginTransaction();

        try {
            $shop->save();
            $connection->commit();
        } catch (UniqueConstraintViolationException $exception) {
            if ($connection->transactionLevel() > $startingTransactionLevel) {
                $connection->rollBack();
            }

            if ($shop->violatesDatabaseTargetIdentity($exception)) {
                throw TenantDatabaseTargetConflict::alreadyAssigned();
            }

            throw $exception;
        } catch (\Throwable $exception) {
            if ($connection->transactionLevel() > $startingTransactionLevel) {
                $connection->rollBack();
            }

            throw $exception;
        } finally {
            $shop->prevalidatedDatabaseTarget = null;
        }

        return $shop;
    }

    public function updateProvisioningTarget(
        string $slug,
        #[\SensitiveParameter]
        string $databaseDriver,
        #[\SensitiveParameter]
        string $databaseName,
        #[\SensitiveParameter]
        array|string|null $databaseHost = null,
        #[\SensitiveParameter]
        ?int $databasePort = null,
        #[\SensitiveParameter]
        ?string $databaseUsername = null,
        #[\SensitiveParameter]
        ?string $databasePassword = null,
        #[\SensitiveParameter]
        ?string $databaseSocket = null,
    ): void {
        $target = self::normalizeDatabaseTarget(
            databaseDriver: $databaseDriver,
            databaseName: $databaseName,
            databaseHost: $databaseHost,
            databasePort: $databasePort,
            databaseSocket: $databaseSocket,
        );
        $connection = $this->getConnection();
        $startingTransactionLevel = $connection->transactionLevel();
        $shop = null;
        $connection->beginTransaction();

        try {
            $shop = static::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($shop->provisioned_at !== null || in_array($shop->status, [ShopStatus::Active, ShopStatus::Suspended], true)) {
                throw new LogicException('A provisioned shop database target is immutable.');
            }

            if ($shop->status !== ShopStatus::Failed) {
                throw new LogicException(
                    'Shop database targets can only be changed after provisioning fails and before retry.',
                );
            }

            $shop->allowsProvisioningTargetChange = true;

            try {
                $shop->attributes['slug'] = $slug;
                $shop->applyNormalizedDatabaseTarget($target);
                $shop->setProvisioningCredentials($databaseUsername, $databasePassword);
                $shop->assertDatabaseTargetIsAvailable($target);
                $shop->save();
                $shop->replaceDatabaseTargetClaims($target->claimFingerprints());
            } finally {
                $shop->allowsProvisioningTargetChange = false;
            }

            $attributes = $shop->getAttributes();
            $connection->commit();
        } catch (UniqueConstraintViolationException $exception) {
            if ($connection->transactionLevel() > $startingTransactionLevel) {
                $connection->rollBack();
            }

            if ($shop instanceof self && $shop->violatesDatabaseTargetIdentity($exception)) {
                throw TenantDatabaseTargetConflict::alreadyAssigned();
            }

            throw $exception;
        } catch (\Throwable $exception) {
            if ($connection->transactionLevel() > $startingTransactionLevel) {
                $connection->rollBack();
            }

            throw $exception;
        }

        $this->setRawAttributes($attributes, true);
    }

    public function markProvisioningFailed(string $message): void
    {
        $this->transition(
            from: ShopStatus::Provisioning,
            to: ShopStatus::Failed,
            attributes: [
                'provisioning_failed_at' => now(),
                'provisioning_failure_message' => $message,
            ],
        );
    }

    public function retryProvisioning(): void
    {
        $this->transition(
            from: ShopStatus::Failed,
            to: ShopStatus::Provisioning,
            attributes: [
                'provisioning_failed_at' => null,
                'provisioning_failure_message' => null,
            ],
        );
    }

    public function markActive(): void
    {
        $this->transition(
            from: ShopStatus::Provisioning,
            to: ShopStatus::Active,
            attributes: [
                'provisioned_at' => now(),
                'provisioning_failed_at' => null,
                'provisioning_failure_message' => null,
            ],
        );
    }

    public function suspend(): void
    {
        $this->transition(ShopStatus::Active, ShopStatus::Suspended);
    }

    public function reactivate(): void
    {
        $this->transition(ShopStatus::Suspended, ShopStatus::Active);
    }

    /** @return array<string, mixed> */
    public function databaseConfig(): array
    {
        return $this->validatedDatabaseConnection()->connectionOverrides();
    }

    public function revalidateDatabaseTarget(): NormalizedDatabaseTarget
    {
        return $this->validatedDatabaseConnection()->target();
    }

    public function validatedDatabaseConnection(): ValidatedTenantConnection
    {
        $shop = $this->exists
            ? static::query()->with('databaseTargetClaims')->whereKey($this->getKey())->first()
            : $this;

        if (! $shop instanceof self) {
            throw new LogicException('Deleted shops do not have usable database targets.');
        }

        $registeredFingerprint = (string) $shop->database_target_fingerprint;
        $registeredLocatorFingerprint = $shop->database_target_locator_fingerprint;
        $target = $shop->normalizedDatabaseTarget();

        if ($shop->exists && (! hash_equals($registeredFingerprint, $target->fingerprint)
            || ! self::fingerprintsMatch($registeredLocatorFingerprint, $target->locatorFingerprint))) {
            throw TenantDatabaseTargetConflict::identityChanged();
        }

        if ($shop->exists) {
            $expectedClaims = $target->claimFingerprints();
            $ownedClaims = $shop->databaseTargetClaims
                ->pluck('fingerprint')
                ->map(static fn (mixed $fingerprint): string => (string) $fingerprint)
                ->all();
            sort($expectedClaims, SORT_STRING);
            sort($ownedClaims, SORT_STRING);

            if ($ownedClaims !== $expectedClaims) {
                throw TenantDatabaseTargetConflict::identityChanged();
            }

            $this->setRawAttributes($shop->getRawOriginal(), true);
        }

        $configuration = [
            'driver' => $target->driver,
            'database' => $target->database,
        ];

        foreach ([
            'host' => $target->host,
            'port' => $target->port,
            'unix_socket' => $target->socket,
            'username' => $shop->database_username,
            'password' => $shop->database_password,
        ] as $key => $value) {
            if ($value !== null) {
                $configuration[$key] = $value;
            }
        }

        return new ValidatedTenantConnection($configuration, $target);
    }

    public function forceDelete(): never
    {
        throw new LogicException('Shop hard deletion requires the future verified tenant cleanup workflow.');
    }

    public function forceDeleteQuietly(): never
    {
        throw new LogicException('Shop hard deletion requires the future verified tenant cleanup workflow.');
    }

    public static function forceDestroy($ids): never
    {
        throw new LogicException('Shop hard deletion requires the future verified tenant cleanup workflow.');
    }

    public function materializeSqliteDatabaseIdentityAfterCreation(
        #[\SensitiveParameter]
        SqliteDatabaseIdentitySnapshot $expectedIdentity,
    ): void {
        $attributes = $this->getConnection()->transaction(function () use ($expectedIdentity): array {
            $shop = static::query()->whereKey($this->getKey())->first();

            if (! $shop instanceof self) {
                throw new LogicException('Deleted shops do not have usable database targets.');
            }

            if ($shop->status !== ShopStatus::Provisioning) {
                throw new LogicException(
                    'SQLite database identity can only be materialized while a shop is provisioning.',
                );
            }

            $registeredDatabase = (string) $shop->database_name;
            $registeredFingerprint = (string) $shop->database_target_fingerprint;
            $registeredLocatorFingerprint = $shop->database_target_locator_fingerprint;
            $target = $shop->normalizedDatabaseTarget();

            if (! $expectedIdentity->matchesDatabase($registeredDatabase)
                || $target->database !== $registeredDatabase
                || $target->filesystemIdentity === null
                || ! hash_equals($expectedIdentity->filesystemIdentity, $target->filesystemIdentity)) {
                throw new LogicException(
                    'The SQLite database path no longer matches the exclusive creation snapshot.',
                );
            }

            if ($target->driver !== 'sqlite'
                || ! $target->hasStableFilesystemIdentity
                || $target->pendingSqliteFingerprint === null
                || ! self::fingerprintsMatch(
                    $registeredLocatorFingerprint,
                    $target->pendingSqliteFingerprint,
                )
                || ! hash_equals($registeredFingerprint, $target->pendingSqliteFingerprint)) {
                throw new LogicException(
                    'The shop does not have a newly created SQLite database identity to materialize.',
                );
            }

            $shop->assertDatabaseTargetIsAvailable($target);

            try {
                $shop->applyNormalizedDatabaseTarget($target);
                $shop->saveQuietly();
                $shop->replaceDatabaseTargetClaims($target->claimFingerprints());
            } catch (UniqueConstraintViolationException $exception) {
                if ($shop->violatesDatabaseTargetIdentity($exception)) {
                    throw TenantDatabaseTargetConflict::alreadyAssigned();
                }

                throw $exception;
            }

            return $shop->getAttributes();
        });

        $this->setRawAttributes($attributes, true);
    }

    /** @return HasOne<ShopOwner, $this> */
    public function owner(): HasOne
    {
        return $this->hasOne(ShopOwner::class);
    }

    /** @return HasMany<ShopFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(ShopFeature::class);
    }

    /** @return HasMany<ShopAccessSession, $this> */
    public function accessSessions(): HasMany
    {
        return $this->hasMany(ShopAccessSession::class);
    }

    /** @return HasOne<ShopHealthSnapshot, $this> */
    public function healthSnapshot(): HasOne
    {
        return $this->hasOne(ShopHealthSnapshot::class);
    }

    /** @return HasMany<ShopLifecycleActivity, $this> */
    public function lifecycleActivities(): HasMany
    {
        return $this->hasMany(ShopLifecycleActivity::class);
    }

    /** @return HasMany<ShopDatabaseTargetClaim, $this> */
    public function databaseTargetClaims(): HasMany
    {
        return $this->hasMany(ShopDatabaseTargetClaim::class);
    }

    /** @param array<string, mixed> $attributes */
    private function transition(ShopStatus $from, ShopStatus $to, array $attributes = []): void
    {
        $this->updateLocked(function (self $shop) use ($from, $to, $attributes): void {
            if ($shop->status !== $from) {
                throw new LogicException(sprintf(
                    'Cannot transition shop from [%s] to [%s].',
                    $shop->status?->value ?? 'unset',
                    $to->value,
                ));
            }

            $shop->allowsLifecycleTransition = true;

            try {
                $shop->forceFill(['status' => $to, ...$attributes])->save();
            } finally {
                $shop->allowsLifecycleTransition = false;
            }
        });
    }

    /** @param callable(self): void $operation */
    private function updateLocked(callable $operation): void
    {
        $attributes = $this->getConnection()->transaction(function () use ($operation): array {
            $shop = static::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $operation($shop);

            return $shop->getAttributes();
        });

        $this->setRawAttributes($attributes, true);
    }

    private function normalizedDatabaseTarget(): NormalizedDatabaseTarget
    {
        return self::normalizeDatabaseTarget(
            databaseDriver: (string) $this->database_driver,
            databaseName: (string) $this->database_name,
            databaseHost: $this->database_host,
            databasePort: $this->database_port === null ? null : (int) $this->database_port,
            databaseSocket: $this->database_socket,
        );
    }

    private static function normalizeDatabaseTarget(
        #[\SensitiveParameter]
        string $databaseDriver,
        #[\SensitiveParameter]
        string $databaseName,
        #[\SensitiveParameter]
        array|string|null $databaseHost,
        #[\SensitiveParameter]
        ?int $databasePort,
        #[\SensitiveParameter]
        ?string $databaseSocket,
    ): NormalizedDatabaseTarget {
        $tenantConfiguration = config('database.tenant_connection_template')
            ?? config('database.connections.tenant');
        $centralConfiguration = config('database.connections.central');
        $sqliteRoot = config('database.tenant_sqlite_root');

        if (! is_array($tenantConfiguration)
            || ! is_array($centralConfiguration)
            || ! is_string($sqliteRoot)
            || $sqliteRoot === '') {
            throw new LogicException('Tenant database target configuration is incomplete.');
        }

        return NormalizedDatabaseTarget::forTenant(
            target: new DatabaseTargetConfiguration(
                driver: $databaseDriver,
                database: $databaseName,
                host: $databaseHost,
                port: $databasePort,
                socket: $databaseSocket,
            ),
            defaults: DatabaseTargetConfiguration::fromLaravelConfiguration($tenantConfiguration),
            central: DatabaseTargetConfiguration::fromLaravelConfiguration($centralConfiguration),
            sqliteRoot: $sqliteRoot,
            hostResolver: resolve(DatabaseHostResolver::class),
        );
    }

    private function applyNormalizedDatabaseTarget(
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        $this->attributes['database_driver'] = $target->driver;
        $this->attributes['database_name'] = $target->database;
        $this->attributes['database_target_fingerprint'] = $target->fingerprint;
        $this->attributes['database_target_locator_fingerprint'] = $target->locatorFingerprint;

        foreach ([
            'database_host' => $target->host,
            'database_port' => $target->port,
            'database_socket' => $target->socket,
        ] as $attribute => $value) {
            if (! $this->encryptedAttributeMatches($attribute, $value)) {
                $this->setEncryptedAttribute($attribute, $value);
            }
        }

        $this->pendingDatabaseTargetClaimFingerprints = $target->claimFingerprints();
    }

    private function encryptedAttributeMatches(
        string $attribute,
        #[\SensitiveParameter]
        array|int|string|null $value,
    ): bool {
        $currentValue = $this->getAttribute($attribute);

        if ($currentValue === null || $value === null) {
            return $currentValue === $value;
        }

        if (is_array($currentValue) || is_array($value)) {
            return $currentValue === $value;
        }

        return (string) $currentValue === (string) $value;
    }

    private function setProvisioningCredentials(
        #[\SensitiveParameter]
        ?string $databaseUsername,
        #[\SensitiveParameter]
        ?string $databasePassword,
    ): void {
        foreach ([
            'database_username' => $databaseUsername,
            'database_password' => $databasePassword,
        ] as $attribute => $value) {
            if (! $this->encryptedAttributeMatches($attribute, $value)) {
                $this->setEncryptedAttribute($attribute, $value);
            }
        }
    }

    private function setEncryptedAttribute(
        string $attribute,
        #[\SensitiveParameter]
        array|int|string|null $value,
    ): void {
        if ($attribute === 'database_host' && $value !== null) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }

        $this->attributes[$attribute] = $value === null
            ? null
            : $this->castAttributeAsEncryptedString($attribute, $value);
    }

    private function assertDatabaseTargetIsAvailable(
        #[\SensitiveParameter]
        NormalizedDatabaseTarget $target,
    ): void {
        $shopId = $this->exists ? (string) $this->getKey() : null;
        $claimQuery = ShopDatabaseTargetClaim::query()->whereIn(
            'fingerprint',
            $target->claimFingerprints(),
        );

        if ($shopId !== null) {
            $claimQuery->where('shop_id', '!=', $shopId);
        }

        if ($claimQuery->exists() || self::databaseTargetIsAssignedToAnotherShop(
            $target->fingerprint,
            $target->locatorFingerprint,
            $shopId,
        )) {
            throw TenantDatabaseTargetConflict::alreadyAssigned();
        }
    }

    /** @param list<string> $fingerprints */
    private function replaceDatabaseTargetClaims(array $fingerprints): void
    {
        $this->databaseTargetClaims()->delete();

        foreach ($fingerprints as $fingerprint) {
            $claim = new ShopDatabaseTargetClaim;
            $claim->forceFill([
                'shop_id' => $this->getKey(),
                'fingerprint' => $fingerprint,
            ]);
            $claim->save();
        }
    }

    private function violatesDatabaseTargetIdentity(UniqueConstraintViolationException $exception): bool
    {
        return in_array('database_target_fingerprint', $exception->columns, true)
            || $exception->index === self::DATABASE_TARGET_UNIQUE_INDEX
            || in_array('database_target_locator_fingerprint', $exception->columns, true)
            || $exception->index === self::DATABASE_TARGET_LOCATOR_UNIQUE_INDEX
            || in_array('fingerprint', $exception->columns, true)
            || $exception->index === self::DATABASE_TARGET_CLAIM_UNIQUE_INDEX
            || self::databaseTargetIsAssignedToAnotherShop(
                (string) $this->database_target_fingerprint,
                $this->database_target_locator_fingerprint,
                $this->exists ? (string) $this->getKey() : null,
            );
    }

    private static function databaseTargetIsAssignedToAnotherShop(
        string $fingerprint,
        ?string $locatorFingerprint,
        ?string $shopId,
    ): bool {
        $query = static::withTrashed()->where(
            static function (Builder $query) use ($fingerprint, $locatorFingerprint): void {
                $query->where('database_target_fingerprint', $fingerprint);

                if ($locatorFingerprint !== null) {
                    $query->orWhere('database_target_locator_fingerprint', $locatorFingerprint);
                }
            },
        );

        if ($shopId !== null) {
            $query->whereKeyNot($shopId);
        }

        return $query->exists();
    }

    private static function fingerprintsMatch(?string $first, ?string $second): bool
    {
        if ($first === null || $second === null) {
            return $first === $second;
        }

        return hash_equals($first, $second);
    }
}
