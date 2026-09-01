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

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return HasMany<ShopAccessSession, $this> */
    public function shopAccessSessions(): HasMany
    {
        return $this->hasMany(ShopAccessSession::class);
    }
}
