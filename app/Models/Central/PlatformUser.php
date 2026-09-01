<?php

namespace App\Models\Central;

use Database\Factories\Central\PlatformUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PlatformUser extends Authenticatable
{
    /** @use HasFactory<PlatformUserFactory> */
    use HasFactory, Notifiable;

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
        $this->forceFill(['is_active' => false])->save();
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
