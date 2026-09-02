<?php

namespace App\Tenancy\Provisioning;

use App\Actions\Tenancy\RecordShopLifecycleActivity;
use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Exceptions\TenantProvisioningException;
use App\Models\Central\Shop;
use App\Tenancy\ValidatedTenantConnection;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class MySqlDatabaseProvisioner implements DatabaseProvisioner
{
    public function __construct(
        private MySqlServerConnectionFactory $serverConnectionFactory,
        private TenantInstallationBootstrapper $bootstrapper,
        private RecordShopLifecycleActivity $lifecycleActivity,
        private TenantProvisioningHook $hook,
    ) {}

    public function provision(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
    ): void {
        $attemptFingerprint = (string) $shop->database_target_fingerprint;
        [$freshShop, $snapshot] = $this->reloadProvisioningShop($shop, $attemptFingerprint);
        $database = $snapshot->target()->database;
        $server = $this->serverConnectionFactory->open($snapshot);
        $targetAlreadyExists = false;
        $targetMayBeAmbiguous = false;

        try {
            try {
                $exists = $server->databaseExists($database);
            } catch (TenantProvisioningException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw TenantProvisioningException::safe(
                    'database',
                    'MYSQL_DATABASE_CHECK_FAILED',
                    'The MySQL database could not be checked. Review the application log code and retry.',
                );
            }

            if ($exists) {
                $targetAlreadyExists = true;
            } else {
                [$freshShop, $freshSnapshot] = $this->reloadProvisioningShop(
                    $freshShop,
                    $attemptFingerprint,
                );

                if (! hash_equals($database, $freshSnapshot->target()->database)) {
                    throw TenantProvisioningException::safe(
                        'target',
                        'TARGET_IDENTITY_CHANGED',
                        'The tenant database target changed. Review it before retrying.',
                    );
                }

                $lease->heartbeat();

                try {
                    $server->createDatabase($database);
                } catch (TenantProvisioningInterrupted $exception) {
                    throw $exception;
                } catch (Throwable) {
                    $targetAlreadyExists = $this->targetExistsAfterCreateFailure($server, $database);
                    $targetMayBeAmbiguous = $targetAlreadyExists;
                }
            }

            if (! $targetAlreadyExists) {
                $this->hook->reached(
                    TenantProvisioningCheckpoint::AfterPhysicalCreateBeforeAuthorization,
                    $freshShop,
                );
                $this->publishCreationAuthorization($freshShop, $attemptFingerprint, $lease);
                $this->hook->reached(
                    TenantProvisioningCheckpoint::AfterAuthorization,
                    $freshShop->fresh(),
                );
                $lease->heartbeat();
            }
        } finally {
            try {
                $server->close();
            } catch (Throwable) {
            }
        }

        if ($targetAlreadyExists) {
            $this->resumeExistingTarget($freshShop, $lease, $targetMayBeAmbiguous);

            return;
        }

        $this->bootstrapper->ensureInstalled(
            Shop::query()->findOrFail($shop->getKey()),
            TenantInstallationReason::ExclusiveCreate,
            $lease,
        );
    }

    /** @return array{Shop, ValidatedTenantConnection} */
    private function reloadProvisioningShop(
        #[\SensitiveParameter]
        Shop $shop,
        string $attemptFingerprint,
    ): array {
        $freshShop = Shop::query()->whereKey($shop->getKey())->first();

        if (! $freshShop instanceof Shop || $freshShop->status !== ShopStatus::Provisioning) {
            throw TenantProvisioningException::safe(
                'target',
                'SHOP_NOT_PROVISIONING',
                'The shop is not available for provisioning.',
            );
        }

        if (! hash_equals($attemptFingerprint, (string) $freshShop->database_target_fingerprint)) {
            throw TenantProvisioningException::safe(
                'target',
                'TARGET_IDENTITY_CHANGED',
                'The tenant database target changed. Review it before retrying.',
            );
        }

        try {
            $snapshot = $freshShop->validatedDatabaseConnection();
        } catch (Throwable) {
            throw TenantProvisioningException::safe(
                'target',
                'TARGET_IDENTITY_CHANGED',
                'The tenant database target changed. Review it before retrying.',
            );
        }

        if ($snapshot->target()->driver !== 'mysql'
            || ! hash_equals($attemptFingerprint, $snapshot->target()->fingerprint)) {
            throw TenantProvisioningException::safe(
                'target',
                'TARGET_IDENTITY_CHANGED',
                'The tenant database target changed. Review it before retrying.',
            );
        }

        return [$freshShop, $snapshot];
    }

    private function targetExistsAfterCreateFailure(
        #[\SensitiveParameter]
        MySqlServerConnection $server,
        #[\SensitiveParameter]
        string $database,
    ): bool {
        try {
            if ($server->databaseExists($database)) {
                return true;
            }
        } catch (Throwable) {
        }

        throw TenantProvisioningException::safe(
            'database',
            'DATABASE_CREATE_FAILED',
            'The tenant database could not be created. Review the application log code and retry.',
        );
    }

    private function resumeExistingTarget(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
        bool $targetMayBeAmbiguous = false,
    ): void {
        try {
            $this->bootstrapper->ensureInstalled(
                $shop,
                TenantInstallationReason::ExclusiveCreate,
                $lease,
            );
        } catch (TenantProvisioningException $exception) {
            if ($exception->errorCode === 'TARGET_ALREADY_EXISTS'
                && ($targetMayBeAmbiguous
                    || $shop->lifecycleActivities()
                        ->where('event', ShopLifecycleEvent::ProvisioningStarted->value)
                        ->count() > 1)) {
                throw TenantProvisioningException::safe(
                    'database',
                    'TARGET_BOOTSTRAP_AMBIGUOUS',
                    'The tenant database exists without a durable creation receipt. Operator review is required.',
                );
            }

            throw $exception;
        }
    }

    private function publishCreationAuthorization(
        #[\SensitiveParameter]
        Shop $shop,
        string $attemptFingerprint,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
    ): void {
        DB::connection('central')->transaction(function () use (
            $shop,
            $attemptFingerprint,
            $lease,
        ): void {
            $lockedShop = Shop::query()
                ->whereKey($shop->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedShop instanceof Shop
                || $lockedShop->status !== ShopStatus::Provisioning
                || ! hash_equals($attemptFingerprint, (string) $lockedShop->database_target_fingerprint)) {
                throw TenantProvisioningException::safe(
                    'target',
                    'TARGET_IDENTITY_CHANGED',
                    'The tenant database target changed. Review it before retrying.',
                );
            }

            $lease->heartbeat();
            $lockedShop->validatedDatabaseConnection();
            $lease->heartbeat();
            $this->lifecycleActivity->handle(
                $lockedShop,
                ShopLifecycleEvent::TenantInstallationAuthorized,
                metadata: [
                    'database_driver' => 'mysql',
                    'reason_code' => TenantInstallationReason::ExclusiveCreate->value,
                    'target_fingerprint' => $lockedShop->database_target_fingerprint,
                ],
            );
        });
    }
}
