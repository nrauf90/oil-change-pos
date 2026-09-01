<?php

namespace App\Models\Central;

use App\Enums\ShopStatus;
use Database\Factories\Central\ShopFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use LogicException;

class Shop extends CentralModel
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    private const DATABASE_TARGET_ATTRIBUTES = [
        'slug',
        'database_driver',
        'database_name',
        'database_host',
        'database_port',
        'database_username',
        'database_password',
    ];

    private const SUPPORTED_DATABASE_DRIVERS = ['mysql', 'sqlite'];

    protected $fillable = ['name', 'timezone', 'currency'];

    protected $hidden = ['database_host', 'database_port', 'database_username', 'database_password'];

    private bool $allowsLifecycleTransition = false;

    private bool $allowsProvisioningTargetChange = false;

    protected function casts(): array
    {
        return [
            'status' => ShopStatus::class,
            'database_host' => 'encrypted',
            'database_port' => 'encrypted',
            'database_username' => 'encrypted',
            'database_password' => 'encrypted',
            'provisioning_failed_at' => 'datetime',
            'provisioned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $shop): void {
            self::assertSupportedDatabaseDriver((string) $shop->database_driver);

            if ($shop->exists && $shop->isDirty('status') && ! $shop->allowsLifecycleTransition) {
                throw new LogicException('Shop status must be changed through a lifecycle method.');
            }

            if ($shop->exists
                && $shop->isDirty(self::DATABASE_TARGET_ATTRIBUTES)
                && ! $shop->allowsProvisioningTargetChange) {
                throw new LogicException('Shop database targets must be changed through updateProvisioningTarget().');
            }
        });
    }

    public static function registerForProvisioning(
        string $name,
        string $slug,
        string $databaseDriver,
        string $databaseName,
        ?string $databaseHost = null,
        ?int $databasePort = null,
        ?string $databaseUsername = null,
        ?string $databasePassword = null,
        string $timezone = 'Asia/Karachi',
        string $currency = 'PKR',
    ): self {
        self::assertSupportedDatabaseDriver($databaseDriver);

        $shop = new self;
        $shop->forceFill([
            'name' => $name,
            'slug' => $slug,
            'status' => ShopStatus::Provisioning,
            'database_driver' => $databaseDriver,
            'database_name' => $databaseName,
            'database_host' => $databaseHost,
            'database_port' => $databasePort,
            'database_username' => $databaseUsername,
            'database_password' => $databasePassword,
            'timezone' => $timezone,
            'currency' => $currency,
        ])->save();

        return $shop;
    }

    public function updateProvisioningTarget(
        string $slug,
        string $databaseDriver,
        string $databaseName,
        ?string $databaseHost = null,
        ?int $databasePort = null,
        ?string $databaseUsername = null,
        ?string $databasePassword = null,
    ): void {
        if ($this->provisioned_at !== null || in_array($this->status, [ShopStatus::Active, ShopStatus::Suspended], true)) {
            throw new LogicException('A provisioned shop database target is immutable.');
        }

        self::assertSupportedDatabaseDriver($databaseDriver);
        $this->allowsProvisioningTargetChange = true;

        try {
            $this->forceFill([
                'slug' => $slug,
                'database_driver' => $databaseDriver,
                'database_name' => $databaseName,
                'database_host' => $databaseHost,
                'database_port' => $databasePort,
                'database_username' => $databaseUsername,
                'database_password' => $databasePassword,
            ])->save();
        } finally {
            $this->allowsProvisioningTargetChange = false;
        }
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
        return array_filter([
            'driver' => $this->database_driver,
            'database' => $this->database_name,
            'host' => $this->database_host,
            'port' => $this->database_port === null ? null : (int) $this->database_port,
            'username' => $this->database_username,
            'password' => $this->database_password,
        ], static fn (mixed $value): bool => $value !== null);
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
        if ($this->status !== $from) {
            throw new LogicException(sprintf(
                'Cannot transition shop from [%s] to [%s].',
                $this->status?->value ?? 'unset',
                $to->value,
            ));
        }

        $this->allowsLifecycleTransition = true;

        try {
            $this->forceFill(['status' => $to, ...$attributes])->save();
        } finally {
            $this->allowsLifecycleTransition = false;
        }
    }

    private static function assertSupportedDatabaseDriver(string $databaseDriver): void
    {
        if (! in_array($databaseDriver, self::SUPPORTED_DATABASE_DRIVERS, true)) {
            throw new InvalidArgumentException("Unsupported shop database driver [{$databaseDriver}].");
        }
    }
}
