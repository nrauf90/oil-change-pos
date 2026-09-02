<?php

namespace App\Tenancy\Provisioning;

use App\Exceptions\TenantProvisioningException;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;

final readonly class DatabaseProvisionerManager implements DatabaseProvisioner
{
    public function __construct(
        private MySqlDatabaseProvisioner $mysql,
        private SqliteDatabaseProvisioner $sqlite,
    ) {}

    public function provision(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
        ?PlatformUser $actor = null,
    ): void {
        $freshShop = Shop::query()->whereKey($shop->getKey())->first();

        if (! $freshShop instanceof Shop) {
            throw TenantProvisioningException::safe(
                'target',
                'SHOP_NOT_FOUND',
                'The shop is no longer available for provisioning.',
            );
        }

        match ($freshShop->database_driver) {
            'sqlite' => $this->sqlite->provision($freshShop, $lease, $actor),
            'mysql' => $this->mysql->provision($freshShop, $lease, $actor),
            default => throw TenantProvisioningException::safe(
                'target',
                'UNSUPPORTED_DATABASE_DRIVER',
                'The tenant database driver is not supported.',
            ),
        };
    }
}
