<?php

namespace App\Tenancy;

use App\Enums\Role;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Models\Central\ShopAccessSession;
use BackedEnum;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class SupportAccessPrincipal implements Authenticatable, Authorizable, FilamentUser, HasAvatar, HasName
{
    private const READ_PERMISSIONS = [
        'pos.use',
        'sales.view_any',
        'sales.view',
        'sales.export_pdf',
        'pricing.view',
        'items.view_any',
        'items.view_unit_cost',
        'items.view_stock',
        'reports.view_dashboard',
        'reports.view_financials',
        'reports.view_margins',
        'expenses.view_any',
        'expenses.view_cash_drawer',
        'users.view_any',
        'scripts.view',
        'service_history.lookup',
        'inspections.view_any',
        'logs.view',
    ];

    public string $name;

    public function __construct(
        public ShopAccessSession $audit,
        public PlatformUser $platformUser,
        public Shop $shop,
    ) {
        $this->name = (string) $platformUser->name;
    }

    public function getAuthIdentifierName(): string
    {
        return 'support_access_audit_id';
    }

    public function getAuthIdentifier(): string
    {
        return (string) $this->audit->getKey();
    }

    public function getKey(): string
    {
        return (string) $this->platformUser->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(mixed $value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function can($abilities, $arguments = []): bool
    {
        foreach ($this->abilities($abilities) as $ability) {
            if (! in_array($ability, self::READ_PERMISSIONS, true)) {
                return false;
            }
        }

        return true;
    }

    public function canAny($abilities, $arguments = []): bool
    {
        foreach ($this->abilities($abilities) as $ability) {
            if (in_array($ability, self::READ_PERMISSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyPermission(mixed ...$permissions): bool
    {
        return $this->canAny($permissions);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin';
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return null;
    }

    public function role(): Role
    {
        return Role::Admin;
    }

    public function roleName(): string
    {
        return Role::Admin->value;
    }

    public function isAdmin(): bool
    {
        return true;
    }

    public function isManager(): bool
    {
        return false;
    }

    public function isTechnician(): bool
    {
        return false;
    }

    /** @return list<string> */
    private function abilities(mixed $abilities): array
    {
        if (is_string($abilities) || $abilities instanceof BackedEnum) {
            $abilities = [$abilities];
        }

        if (! is_iterable($abilities)) {
            return [];
        }

        $normalized = [];

        foreach ($abilities as $ability) {
            if ($ability instanceof BackedEnum) {
                $ability = $ability->value;
            }

            if (is_string($ability)) {
                $normalized[] = $ability;
            }
        }

        return $normalized;
    }
}
