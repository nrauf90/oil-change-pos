<?php

namespace App\Tenancy\Provisioning;

use App\Actions\Tenancy\RecordShopLifecycleActivity;
use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Exceptions\TenantProvisioningException;
use App\Models\Central\PlatformUser;
use App\Models\Central\Shop;
use App\Tenancy\SqliteDatabaseIdentitySnapshot;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SqliteDatabaseProvisioner implements DatabaseProvisioner
{
    public function __construct(
        private TenantInstallationBootstrapper $bootstrapper,
        private RecordShopLifecycleActivity $lifecycleActivity,
        private TenantProvisioningHook $hook,
    ) {}

    public function provision(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
        ?PlatformUser $actor = null,
    ): void {
        $attemptFingerprint = (string) $shop->database_target_fingerprint;
        $freshShop = $this->reloadAttemptShop($shop, $attemptFingerprint);
        $database = (string) $freshShop->database_name;

        if (is_file($database)) {
            $this->resumeExistingTarget(
                $this->reloadExistingProvisioningShop($freshShop, $attemptFingerprint),
                $lease,
            );

            return;
        }

        $freshShop = $this->reloadProvisioningShop($freshShop, $attemptFingerprint);

        if (! is_dir(dirname($database))) {
            throw TenantProvisioningException::safe(
                'database',
                'SQLITE_PARENT_MISSING',
                'The tenant database parent directory does not exist.',
            );
        }

        $lease->heartbeat();
        $handle = @fopen($database, 'x+b');

        if (! is_resource($handle)) {
            $freshShop = $this->reloadAttemptShop($shop, $attemptFingerprint);

            if (is_file($database)) {
                $this->resumeExistingTarget(
                    $this->reloadExistingProvisioningShop($freshShop, $attemptFingerprint),
                    $lease,
                );

                return;
            }

            throw TenantProvisioningException::safe(
                'database',
                'DATABASE_CREATE_FAILED',
                'The tenant database could not be created. Review the application log code and retry.',
            );
        }

        try {
            $identity = SqliteDatabaseIdentitySnapshot::capture($database, $handle);
            $this->hook->reached(
                TenantProvisioningCheckpoint::AfterPhysicalCreateBeforeAuthorization,
                $freshShop,
            );
            $this->publishCreationAuthorization(
                $freshShop,
                $attemptFingerprint,
                $identity,
                $lease,
                $actor,
            );
            $this->hook->reached(
                TenantProvisioningCheckpoint::AfterAuthorization,
                $freshShop->fresh(),
            );
            $lease->heartbeat();
        } catch (TenantProvisioningInterrupted $exception) {
            throw $exception;
        } catch (TenantProvisioningException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw TenantProvisioningException::safe(
                'database',
                'DATABASE_CREATE_FAILED',
                'The tenant database could not be authorized. Review the application log code and retry.',
            );
        } finally {
            fclose($handle);
        }

        $this->bootstrapper->ensureInstalled(
            Shop::query()->findOrFail($shop->getKey()),
            TenantInstallationReason::ExclusiveCreate,
            $lease,
        );
    }

    private function reloadAttemptShop(
        #[\SensitiveParameter]
        Shop $shop,
        string $attemptFingerprint,
    ): Shop {
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

        return $freshShop;
    }

    private function reloadProvisioningShop(
        #[\SensitiveParameter]
        Shop $shop,
        string $attemptFingerprint,
    ): Shop {
        $freshShop = $this->reloadAttemptShop($shop, $attemptFingerprint);

        try {
            $snapshot = $freshShop->validatedDatabaseConnection();
        } catch (Throwable) {
            throw TenantProvisioningException::safe(
                'target',
                'TARGET_IDENTITY_CHANGED',
                'The tenant database target changed. Review it before retrying.',
            );
        }

        if ($snapshot->target()->driver !== 'sqlite'
            || ! hash_equals($attemptFingerprint, $snapshot->target()->fingerprint)) {
            throw TenantProvisioningException::safe(
                'target',
                'TARGET_IDENTITY_CHANGED',
                'The tenant database target changed. Review it before retrying.',
            );
        }

        return $freshShop;
    }

    private function reloadExistingProvisioningShop(
        #[\SensitiveParameter]
        Shop $shop,
        string $attemptFingerprint,
    ): Shop {
        $freshShop = $this->reloadAttemptShop($shop, $attemptFingerprint);
        $locatorFingerprint = $freshShop->database_target_locator_fingerprint;

        if (is_string($locatorFingerprint)
            && hash_equals((string) $freshShop->database_target_fingerprint, $locatorFingerprint)) {
            throw TenantProvisioningException::safe(
                'database',
                'TARGET_BOOTSTRAP_AMBIGUOUS',
                'The tenant database exists without a durable creation receipt. Operator review is required.',
            );
        }

        return $this->reloadProvisioningShop($freshShop, $attemptFingerprint);
    }

    private function resumeExistingTarget(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
    ): void {
        $fingerprint = (string) $shop->database_target_fingerprint;
        $locatorFingerprint = $shop->database_target_locator_fingerprint;

        if (is_string($locatorFingerprint) && hash_equals($fingerprint, $locatorFingerprint)) {
            throw TenantProvisioningException::safe(
                'database',
                'TARGET_BOOTSTRAP_AMBIGUOUS',
                'The tenant database exists without a durable creation receipt. Operator review is required.',
            );
        }

        $this->bootstrapper->ensureInstalled(
            $shop,
            TenantInstallationReason::ExclusiveCreate,
            $lease,
        );
    }

    private function publishCreationAuthorization(
        #[\SensitiveParameter]
        Shop $shop,
        string $attemptFingerprint,
        #[\SensitiveParameter]
        SqliteDatabaseIdentitySnapshot $identity,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
        ?PlatformUser $actor,
    ): void {
        DB::connection('central')->transaction(function () use (
            $shop,
            $attemptFingerprint,
            $identity,
            $lease,
            $actor,
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
            $lockedShop->materializeSqliteDatabaseIdentityAfterCreation($identity);
            $materializedShop = Shop::query()
                ->whereKey($shop->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lease->heartbeat();
            $this->lifecycleActivity->handle(
                $materializedShop,
                ShopLifecycleEvent::TenantInstallationAuthorized,
                $actor,
                metadata: [
                    'database_driver' => 'sqlite',
                    'reason_code' => TenantInstallationReason::ExclusiveCreate->value,
                    'target_fingerprint' => $materializedShop->database_target_fingerprint,
                ],
            );
        });
    }
}
