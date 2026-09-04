<?php

namespace App\Actions\Tenancy;

use App\Models\Central\Shop;
use App\Models\User;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use LogicException;

/**
 * Reads one shop's staff list for the platform control plane.
 *
 * Opens the shop's own database the same way tenant statistics do — through the
 * attested connection manager, with the context asserted clean first and always
 * released afterwards — so a platform screen can never leave a tenant
 * connection dangling for the next request to inherit.
 *
 * Returns a plain array of display fields rather than User models. Nothing that
 * authenticates anyone leaves the tenant database: no password hash, no
 * remember token. The platform administrator is here to see who has access to a
 * shop, not to act as them; taking over an account is what the audited support
 * access session is for.
 */
final readonly class CollectTenantUsers
{
    /** Enough to see who staffs a shop without dragging a whole chain's roster into one page. */
    private const LIMIT = 100;

    public function __construct(
        private TenantConnectionManager $connectionManager,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @return array{
     *     total: int,
     *     shown: int,
     *     users: list<array{
     *         name: string,
     *         username: string,
     *         email: ?string,
     *         roles: string,
     *         is_active: bool,
     *         last_login_at: ?string,
     *         created_at: ?string
     *     }>
     * }
     */
    public function handle(
        #[\SensitiveParameter]
        Shop $shop,
    ): array {
        if ($this->tenantContext->initialized()) {
            throw new LogicException('Tenant users require a clean tenant context.');
        }

        try {
            $this->connectionManager->connect($shop, requireActiveShop: true);

            $total = User::query()->count();

            $users = User::query()
                ->with('roles:id,name')
                ->orderByDesc('is_active')
                ->orderBy('username')
                ->limit(self::LIMIT)
                ->get(['id', 'name', 'username', 'email', 'is_active', 'last_login_at', 'created_at']);

            return [
                'total' => $total,
                'shown' => $users->count(),
                'users' => $users->map(static fn (User $user): array => [
                    'name' => (string) $user->name,
                    'username' => (string) $user->username,
                    'email' => $user->email !== null ? (string) $user->email : null,
                    'roles' => $user->roles->pluck('name')->implode(', '),
                    'is_active' => (bool) $user->is_active,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ])->all(),
            ];
        } finally {
            $this->connectionManager->disconnect();
        }
    }
}
