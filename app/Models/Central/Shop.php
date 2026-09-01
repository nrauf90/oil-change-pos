<?php

namespace App\Models\Central;

use App\Enums\ShopStatus;
use Database\Factories\Central\ShopFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shop extends CentralModel
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'status', 'database_driver', 'database_name', 'database_host', 'database_port',
        'database_username', 'database_password', 'timezone', 'currency', 'provisioning_failed_at',
        'provisioning_failure_message', 'provisioned_at',
    ];

    protected $hidden = ['database_host', 'database_port', 'database_username', 'database_password'];

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
}
