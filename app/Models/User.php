<?php

namespace App\Models;

use App\Enums\Role as RoleEnum;
use App\Models\Concerns\UsesTenantConnection;
use App\Models\Contracts\TenantScoped;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, TenantScoped
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, UsesTenantConnection;

    protected $fillable = ['name', 'username', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The Filament panel is the back office. Technicians never see it — they
     * work from the workshop screens on the main site.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->hasAnyPermission([
            'items.view_any', 'users.view_any', 'expenses.view_any', 'reports.view_dashboard',
        ]);
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'cashier_id');
    }

    public function role(): ?RoleEnum
    {
        $name = $this->roles->first()?->name;

        return $name === null ? null : RoleEnum::tryFrom($name);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(RoleEnum::Admin->value);
    }

    public function isManager(): bool
    {
        return $this->hasRole(RoleEnum::Manager->value);
    }

    public function isTechnician(): bool
    {
        return $this->hasRole(RoleEnum::Technician->value);
    }

    /** A user holds exactly one role in this app. */
    public function assignRoleEnum(RoleEnum $role): void
    {
        $this->syncRoles([$role->value]);
    }

    /** @param Builder<User> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
