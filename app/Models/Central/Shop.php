<?php

namespace App\Models\Central;

use App\Enums\ShopStatus;
use App\Exceptions\TenantDatabaseTargetConflict;
use App\Tenancy\DatabaseHostResolver;
use App\Tenancy\DatabaseTargetConfiguration;
use App\Tenancy\NormalizedDatabaseTarget;
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

    protected function casts(): array
    {
        return [
            'status' => ShopStatus::class,
            'database_host' => 'encrypted',
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
            $target = $shop->normalizeDatabaseTarget();

            if ($shop->exists && $shop->isDirty('status') && ! $shop->allowsLifecycleTransition) {
                throw new LogicException('Shop status must be changed through a lifecycle method.');
            }

            if ($shop->exists
                && $shop->isDirty(self::DATABASE_TARGET_ATTRIBUTES)
                && ! $shop->allowsProvisioningTargetChange) {
                throw new LogicException('Shop database targets must be changed through updateProvisioningTarget().');
            }

            $shop->assertDatabaseTargetIsAvailable($target);
        });
    }

    public static function registerForProvisioning(
        string $name,
        string $slug,
        string $databaseDriver,
        string $databaseName,
        ?string $databaseHost = null,
        ?int $databasePort = null,
        #[\SensitiveParameter]
        ?string $databaseUsername = null,
        #[\SensitiveParameter]
        ?string $databasePassword = null,
        ?string $databaseSocket = null,
        string $timezone = 'Asia/Karachi',
        string $currency = 'PKR',
    ): self {
        $shop = new self;
        $shop->forceFill([
            'name' => $name,
            'slug' => $slug,
            'status' => ShopStatus::Provisioning,
            'database_driver' => $databaseDriver,
            'database_name' => $databaseName,
            'database_host' => $databaseHost,
            'database_port' => $databasePort,
            'database_socket' => $databaseSocket,
            'database_username' => $databaseUsername,
            'database_password' => $databasePassword,
            'timezone' => $timezone,
            'currency' => $currency,
        ]);
        $shop->saveWithDatabaseTargetConflictTranslation();

        return $shop;
    }

    public function updateProvisioningTarget(
        string $slug,
        string $databaseDriver,
        string $databaseName,
        ?string $databaseHost = null,
        ?int $databasePort = null,
        #[\SensitiveParameter]
        ?string $databaseUsername = null,
        #[\SensitiveParameter]
        ?string $databasePassword = null,
        ?string $databaseSocket = null,
    ): void {
        $this->updateLocked(function (self $shop) use (
            $slug,
            $databaseDriver,
            $databaseName,
            $databaseHost,
            $databasePort,
            $databaseUsername,
            $databasePassword,
            $databaseSocket,
        ): void {
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
                $shop->forceFill([
                    'slug' => $slug,
                    'database_driver' => $databaseDriver,
                    'database_name' => $databaseName,
                ]);
                $shop->forceFillEncryptedAttributesIfChanged([
                    'database_host' => $databaseHost,
                    'database_port' => $databasePort,
                    'database_socket' => $databaseSocket,
                    'database_username' => $databaseUsername,
                    'database_password' => $databasePassword,
                ]);
                $shop->saveWithDatabaseTargetConflictTranslation();
            } finally {
                $shop->allowsProvisioningTargetChange = false;
            }
        });
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
        if ($this->exists) {
            $this->revalidateDatabaseTarget();
        }

        return array_filter([
            'driver' => $this->database_driver,
            'database' => $this->database_name,
            'host' => $this->database_host,
            'port' => $this->database_port === null ? null : (int) $this->database_port,
            'unix_socket' => $this->database_socket,
            'username' => $this->database_username,
            'password' => $this->database_password,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function revalidateDatabaseTarget(): void
    {
        $attributes = $this->getConnection()->transaction(function (): array {
            $shop = static::withTrashed()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $registeredFingerprint = (string) $shop->database_target_fingerprint;
            $registeredLocatorFingerprint = $shop->database_target_locator_fingerprint;

            $target = $shop->normalizeDatabaseTarget();

            if (! hash_equals($registeredFingerprint, $target->fingerprint)
                || ! self::fingerprintsMatch($registeredLocatorFingerprint, $target->locatorFingerprint)) {
                throw TenantDatabaseTargetConflict::identityChanged();
            }

            $shop->assertDatabaseTargetIsAvailable($target);

            return $shop->getRawOriginal();
        });

        $this->setRawAttributes($attributes, true);
    }

    public function materializeSqliteDatabaseIdentityAfterCreation(): void
    {
        $attributes = $this->getConnection()->transaction(function (): array {
            $shops = static::withTrashed()
                ->orderBy($this->getKeyName())
                ->lockForUpdate()
                ->get();
            $shop = $shops->firstWhere($this->getKeyName(), $this->getKey());

            if (! $shop instanceof self) {
                $shop = static::withTrashed()->findOrFail($this->getKey());
            }

            if ($shop->status !== ShopStatus::Provisioning) {
                throw new LogicException(
                    'SQLite database identity can only be materialized while a shop is provisioning.',
                );
            }

            $registeredDatabase = (string) $shop->database_name;
            $registeredFingerprint = (string) $shop->database_target_fingerprint;
            $registeredLocatorFingerprint = $shop->database_target_locator_fingerprint;
            $target = $shop->normalizeDatabaseTarget();

            if ($target->driver !== 'sqlite'
                || $target->database !== $registeredDatabase
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
                $shop->saveQuietly();
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

    private function normalizeDatabaseTarget(): NormalizedDatabaseTarget
    {
        $tenantConfiguration = config('database.connections.tenant');
        $centralConfiguration = config('database.connections.central');
        $sqliteRoot = config('database.tenant_sqlite_root');

        if (! is_array($tenantConfiguration)
            || ! is_array($centralConfiguration)
            || ! is_string($sqliteRoot)
            || $sqliteRoot === '') {
            throw new LogicException('Tenant database target configuration is incomplete.');
        }

        $target = NormalizedDatabaseTarget::forTenant(
            target: new DatabaseTargetConfiguration(
                driver: (string) $this->database_driver,
                database: (string) $this->database_name,
                host: $this->database_host,
                port: $this->database_port === null ? null : (int) $this->database_port,
                socket: $this->database_socket,
            ),
            defaults: DatabaseTargetConfiguration::fromLaravelConfiguration($tenantConfiguration),
            central: DatabaseTargetConfiguration::fromLaravelConfiguration($centralConfiguration),
            sqliteRoot: $sqliteRoot,
            hostResolver: resolve(DatabaseHostResolver::class),
        );

        $attributes = [
            'database_driver' => $target->driver,
            'database_name' => $target->database,
            'database_target_fingerprint' => $target->fingerprint,
            'database_target_locator_fingerprint' => $target->locatorFingerprint,
        ];

        foreach ([
            'database_host' => $target->host,
            'database_port' => $target->port,
            'database_socket' => $target->socket,
        ] as $attribute => $value) {
            if (! $this->encryptedAttributeMatches($attribute, $value)) {
                $attributes[$attribute] = $value;
            }
        }

        $this->forceFill($attributes);

        return $target;
    }

    private function encryptedAttributeMatches(
        string $attribute,
        #[\SensitiveParameter]
        int|string|null $value,
    ): bool {
        $currentValue = $this->getAttribute($attribute);

        if ($currentValue === null || $value === null) {
            return $currentValue === $value;
        }

        return (string) $currentValue === (string) $value;
    }

    /** @param array<string, int|string|null> $attributes */
    private function forceFillEncryptedAttributesIfChanged(
        #[\SensitiveParameter]
        array $attributes,
    ): void {
        foreach ($attributes as $attribute => $value) {
            if (! $this->encryptedAttributeMatches($attribute, $value)) {
                $this->forceFill([$attribute => $value]);
            }
        }
    }

    private function assertDatabaseTargetIsAvailable(NormalizedDatabaseTarget $target): void
    {
        if (self::databaseTargetIsAssignedToAnotherShop(
            $target->fingerprint,
            $target->locatorFingerprint,
            $this->exists ? (string) $this->getKey() : null,
        )) {
            throw TenantDatabaseTargetConflict::alreadyAssigned();
        }
    }

    private function saveWithDatabaseTargetConflictTranslation(): void
    {
        try {
            $this->save();
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->violatesDatabaseTargetIdentity($exception)) {
                throw TenantDatabaseTargetConflict::alreadyAssigned();
            }

            throw $exception;
        }
    }

    private function violatesDatabaseTargetIdentity(UniqueConstraintViolationException $exception): bool
    {
        return in_array('database_target_fingerprint', $exception->columns, true)
            || $exception->index === self::DATABASE_TARGET_UNIQUE_INDEX
            || in_array('database_target_locator_fingerprint', $exception->columns, true)
            || $exception->index === self::DATABASE_TARGET_LOCATOR_UNIQUE_INDEX
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
