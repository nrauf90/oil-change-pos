<?php

namespace App\Models\Central;

use App\Support\PlatformSessionAuthentication;
use Database\Factories\Central\PlatformUserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use LogicException;

class PlatformUser extends Authenticatable implements FilamentUser
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
        $connectionName = $this->getConnectionName() ?? 'central';
        $attributes = $this->getConnection()->transaction(function () use ($connectionName): array {
            $platformUser = static::on($connectionName)
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $platformUser->is_active) {
                ShopAccessSession::endActiveForPlatformUser($platformUser);
                $platformUser->setRememberToken(Str::random(60));
                $platformUser->forceFill(['is_active' => true])->save();
                app(PlatformSessionAuthentication::class)->revokePersistedSessions(
                    $platformUser->getKey(),
                    $connectionName,
                );
            }

            return $platformUser->getAttributes();
        });

        $this->setRawAttributes($attributes, true);
        app(PlatformSessionAuthentication::class)->forgetCurrentAuthentication($this->getKey());
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'platform'
            && $this->role === self::ROLE_SUPER_ADMIN
            && $this->is_active;
    }

    public function deactivate(): void
    {
        $connectionName = $this->getConnectionName() ?? 'central';
        $result = $this->getConnection()->transaction(function () use ($connectionName): array {
            $platformUsers = static::on($connectionName)
                ->where('role', self::ROLE_SUPER_ADMIN)
                ->orWhere($this->getKeyName(), $this->getKey())
                ->orderBy($this->getKeyName())
                ->lockForUpdate()
                ->get();
            $platformUser = $platformUsers->firstWhere($this->getKeyName(), $this->getKey());
            $platformUser ??= static::on($connectionName)->lockForUpdate()->findOrFail($this->getKey());

            if ($platformUser->is_active) {
                $activeSuperAdminCount = $platformUsers
                    ->filter(static fn (self $user): bool => $user->role === self::ROLE_SUPER_ADMIN && $user->is_active)
                    ->count();

                if ($platformUser->role === self::ROLE_SUPER_ADMIN && $activeSuperAdminCount === 1) {
                    throw new LogicException('The final active super administrator cannot be deactivated.');
                }
            }

            ShopAccessSession::endActiveForPlatformUser($platformUser);

            if ($platformUser->is_active) {
                $platformUser->setRememberToken(Str::random(60));
                $platformUser->forceFill(['is_active' => false])->save();
            }

            app(PlatformSessionAuthentication::class)->revokePersistedSessions(
                $platformUser->getKey(),
                $connectionName,
            );

            return [
                'attributes' => $platformUser->getAttributes(),
            ];
        });

        $this->setRawAttributes($result['attributes'], true);
        app(PlatformSessionAuthentication::class)->forgetCurrentAuthentication($this->getKey());
    }

    public function delete(): ?bool
    {
        if (! $this->exists) {
            return parent::delete();
        }

        $connectionName = $this->getConnectionName() ?? 'central';

        return $this->getConnection()->transaction(function () use ($connectionName): ?bool {
            $platformUsers = static::on($connectionName)
                ->where('role', self::ROLE_SUPER_ADMIN)
                ->orWhere($this->getKeyName(), $this->getKey())
                ->orderBy($this->getKeyName())
                ->lockForUpdate()
                ->get();
            $platformUser = $platformUsers->firstWhere($this->getKeyName(), $this->getKey());
            $platformUser ??= static::on($connectionName)->lockForUpdate()->findOrFail($this->getKey());

            $activeSuperAdminCount = $platformUsers
                ->filter(static fn (self $user): bool => $user->role === self::ROLE_SUPER_ADMIN && $user->is_active)
                ->count();

            if ($platformUser->role === self::ROLE_SUPER_ADMIN
                && $platformUser->is_active
                && $activeSuperAdminCount === 1) {
                throw new LogicException('The final active super administrator cannot be deleted.');
            }

            $this->setRawAttributes($platformUser->getAttributes(), true);

            return parent::delete();
        });
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
