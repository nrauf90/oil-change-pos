<?php

namespace App\Models\Central;

use Database\Factories\Central\PlatformUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;

class PlatformUser extends Authenticatable
{
    /** @use HasFactory<PlatformUserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    protected $connection = 'central';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function activate(): void
    {
        $this->forceFill(['is_active' => true])->save();
    }

    public function deactivate(): void
    {
        $attributes = $this->getConnection()->transaction(function (): array {
            $platformUsers = static::query()
                ->where('role', self::ROLE_SUPER_ADMIN)
                ->orWhere($this->getKeyName(), $this->getKey())
                ->orderBy($this->getKeyName())
                ->lockForUpdate()
                ->get();
            $platformUser = $platformUsers->firstWhere($this->getKeyName(), $this->getKey());
            $platformUser ??= static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! $platformUser->is_active) {
                return $platformUser->getAttributes();
            }

            $activeSuperAdminCount = $platformUsers
                ->filter(static fn (self $user): bool => $user->role === self::ROLE_SUPER_ADMIN && $user->is_active)
                ->count();

            if ($platformUser->role === self::ROLE_SUPER_ADMIN && $activeSuperAdminCount === 1) {
                throw new LogicException('The final active super administrator cannot be deactivated.');
            }

            $platformUser->forceFill(['is_active' => false])->save();

            return $platformUser->getAttributes();
        });

        $this->setRawAttributes($attributes, true);
    }

    public function recordSuccessfulLogin(): void
    {
        $this->forceFill(['last_login_at' => now()])->save();
    }

    /** @return HasMany<ShopAccessSession, $this> */
    public function shopAccessSessions(): HasMany
    {
        return $this->hasMany(ShopAccessSession::class);
    }

    /** @return HasMany<ShopLifecycleActivity, $this> */
    public function shopLifecycleActivities(): HasMany
    {
        return $this->hasMany(ShopLifecycleActivity::class);
    }
}
