<?php

namespace App\Actions\Tenancy;

use App\Data\ProvisionShopData;
use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Exceptions\TenantDatabaseTargetConflict;
use App\Exceptions\TenantProvisioningException;
use App\Models\Central\Shop;
use App\Models\Central\ShopFeature;
use App\Models\Central\ShopHealthSnapshot;
use App\Models\Central\ShopOwner;
use App\Modules\ModuleRegistry;
use App\Tenancy\Migrations\TenantMigrationResult;
use App\Tenancy\Migrations\TenantMigrationRunner;
use App\Tenancy\Provisioning\DatabaseProvisioner;
use App\Tenancy\Provisioning\TenantOwnerProvisioner;
use App\Tenancy\Provisioning\TenantProvisioningCheckpoint;
use App\Tenancy\Provisioning\TenantProvisioningHook;
use App\Tenancy\Provisioning\TenantProvisioningInterrupted;
use App\Tenancy\Provisioning\TenantProvisioningLease;
use App\Tenancy\Provisioning\TenantProvisioningLock;
use App\Tenancy\TenantConnectionManager;
use Closure;
use Database\Seeders\TenantReferenceDataSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final readonly class ProvisionShop
{
    public function __construct(
        private DatabaseProvisioner $databaseProvisioner,
        private TenantProvisioningLock $provisioningLock,
        private TenantConnectionManager $connectionManager,
        private TenantMigrationRunner $migrationRunner,
        private TenantOwnerProvisioner $ownerProvisioner,
        private SyncTenantAuthorization $syncTenantAuthorization,
        private TenantReferenceDataSeeder $referenceDataSeeder,
        private RecordShopLifecycleActivity $recordLifecycleActivity,
        private ModuleRegistry $moduleRegistry,
        private TenantProvisioningHook $hook,
    ) {}

    public function handle(#[\SensitiveParameter] ProvisionShopData $data): Shop
    {
        $this->assertKnownFeatures($data->initialFeatureKeys);
        $shop = $this->register($data);

        return $this->provisioningLock->run(
            $shop,
            fn (#[\SensitiveParameter] TenantProvisioningLease $lease): Shop => $this->runAttempt(
                $shop,
                $data->temporaryOwnerPassword,
                1,
                $lease,
            ),
        );
    }

    public function retry(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        ?string $temporaryPassword = null,
    ): Shop {
        $attemptFingerprint = (string) $shop->database_target_fingerprint;

        return $this->provisioningLock->run(
            $shop,
            function (#[\SensitiveParameter] TenantProvisioningLease $lease) use (
                $shop,
                $temporaryPassword,
                $attemptFingerprint,
            ): Shop {
                [$freshShop, $attempt] = $this->prepareRetry(
                    $shop,
                    $attemptFingerprint,
                    $lease,
                );

                if ($freshShop->status === ShopStatus::Active) {
                    return $freshShop;
                }

                return $this->runAttempt($freshShop, $temporaryPassword, $attempt, $lease);
            },
        );
    }

    /** @param list<string> $featureKeys */
    private function assertKnownFeatures(array $featureKeys): void
    {
        foreach ($featureKeys as $featureKey) {
            if (! $this->moduleRegistry->has($featureKey)) {
                throw TenantProvisioningException::safe(
                    'authorization',
                    'UNKNOWN_FEATURE',
                    'An initial shop feature is not registered by the application.',
                );
            }
        }
    }

    private function register(#[\SensitiveParameter] ProvisionShopData $data): Shop
    {
        try {
            return DB::connection('central')->transaction(function () use ($data): Shop {
                $shop = Shop::registerForProvisioning(
                    name: $data->name,
                    slug: $data->slug,
                    databaseDriver: $data->databaseDriver,
                    databaseName: $data->databaseName,
                    databaseHost: $data->databaseHost,
                    databasePort: $data->databasePort,
                    databaseUsername: $data->databaseUsername,
                    databasePassword: $data->databasePassword,
                    databaseSocket: $data->databaseSocket,
                    timezone: $data->timezone,
                    currency: $data->currency,
                );

                ShopOwner::query()->create([
                    'shop_id' => $shop->getKey(),
                    'name' => $data->ownerName,
                    'username' => $data->ownerUsername,
                    'email' => $data->ownerEmail,
                    'is_active' => true,
                ]);

                foreach ($data->initialFeatureKeys as $featureKey) {
                    ShopFeature::query()->create([
                        'shop_id' => $shop->getKey(),
                        'module_key' => $featureKey,
                        'enabled' => true,
                    ]);
                }

                $this->recordLifecycleActivity->handle(
                    $shop,
                    ShopLifecycleEvent::ProvisioningStarted,
                    metadata: [
                        'attempt' => 1,
                        'database_driver' => $data->databaseDriver,
                    ],
                );

                return $shop;
            });
        } catch (TenantDatabaseTargetConflict) {
            throw TenantProvisioningException::safe(
                'target',
                'TARGET_ALREADY_ASSIGNED',
                'The tenant database target is already assigned to another shop.',
            );
        } catch (UniqueConstraintViolationException) {
            throw TenantProvisioningException::safe(
                'target',
                'SHOP_SLUG_ALREADY_EXISTS',
                'The shop slug is already registered.',
            );
        } catch (TenantProvisioningException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw TenantProvisioningException::safe(
                'target',
                'SHOP_REGISTRATION_FAILED',
                'The shop could not be registered for provisioning.',
            );
        }
    }

    /** @return array{Shop, int} */
    private function prepareRetry(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        string $attemptFingerprint,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
    ): array {
        $lease->heartbeat();

        return DB::connection('central')->transaction(function () use (
            $shop,
            $attemptFingerprint,
            $lease,
        ): array {
            $freshShop = Shop::query()
                ->whereKey($shop->getKey())
                ->lockForUpdate()
                ->first();

            if (! $freshShop instanceof Shop) {
                throw TenantProvisioningException::safe(
                    'target',
                    'SHOP_NOT_FOUND',
                    'The shop is no longer available for provisioning.',
                );
            }

            if ($attemptFingerprint === ''
                || ! hash_equals(
                    $attemptFingerprint,
                    (string) $freshShop->database_target_fingerprint,
                )) {
                throw TenantProvisioningException::safe(
                    'target',
                    'TARGET_IDENTITY_CHANGED',
                    'The tenant database target changed. Review it before retrying.',
                );
            }

            if ($freshShop->status === ShopStatus::Active && $freshShop->provisioned_at !== null) {
                return [$freshShop, 0];
            }

            if ($freshShop->status === ShopStatus::Suspended) {
                throw TenantProvisioningException::safe(
                    'target',
                    'SHOP_SUSPENDED',
                    'A suspended shop cannot be provisioned.',
                );
            }

            if ($freshShop->status === ShopStatus::Active) {
                throw TenantProvisioningException::safe(
                    'target',
                    'SHOP_STATE_INVALID',
                    'The shop provisioning state requires operator review.',
                );
            }

            if ($freshShop->status === ShopStatus::Failed) {
                $lease->heartbeat();
                $freshShop->retryProvisioning();
            } elseif ($freshShop->status !== ShopStatus::Provisioning) {
                throw TenantProvisioningException::safe(
                    'target',
                    'SHOP_STATE_INVALID',
                    'The shop provisioning state requires operator review.',
                );
            }

            $attempt = $freshShop->lifecycleActivities()
                ->where('event', ShopLifecycleEvent::ProvisioningStarted->value)
                ->count() + 1;
            $lease->heartbeat();
            $this->recordLifecycleActivity->handle(
                $freshShop,
                ShopLifecycleEvent::ProvisioningStarted,
                metadata: [
                    'attempt' => $attempt,
                    'database_driver' => $freshShop->database_driver,
                ],
            );

            return [$freshShop->fresh(), $attempt];
        });
    }

    private function runAttempt(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        ?string $temporaryPassword,
        int $attempt,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
    ): Shop {
        try {
            $freshShop = $this->freshProvisioningShop($shop);
            $lease->heartbeat();
            $this->safely(
                'database',
                'DATABASE_PROVISION_FAILED',
                'The tenant database could not be provisioned. Review the application log code and retry.',
                fn (): mixed => $this->databaseProvisioner->provision($freshShop, $lease),
            );
            $freshShop = $this->freshProvisioningShop($freshShop);
            $centralOwner = $freshShop->owner()->first();

            if (! $centralOwner instanceof ShopOwner) {
                throw TenantProvisioningException::safe(
                    'owner',
                    'CENTRAL_OWNER_MISSING',
                    'The central shop owner record is missing.',
                );
            }

            /** @var TenantMigrationResult $migrationResult */
            $lease->heartbeat();
            $migrationResult = $this->safely(
                'connection',
                'TENANT_CONNECTION_FAILED',
                'The tenant connection could not be attested. Review the application log code and retry.',
                fn (): mixed => $this->connectionManager->within(
                    $freshShop,
                    function () use (
                        $centralOwner,
                        $temporaryPassword,
                        $lease,
                    ): TenantMigrationResult {
                        $migrationResult = $this->migrationRunner->runConnected($lease);
                        $this->hook->reached(
                            TenantProvisioningCheckpoint::AfterTenantMigrations,
                            Shop::query()->whereKey($centralOwner->shop_id)->firstOrFail(),
                        );
                        $lease->heartbeat();

                        DB::connection('tenant')->transaction(function () use (
                            $centralOwner,
                            $temporaryPassword,
                            $lease,
                        ): void {
                            $owner = $this->safely(
                                'owner',
                                'OWNER_PROVISION_FAILED',
                                'The tenant owner could not be provisioned. Review the application log code and retry.',
                                function () use ($centralOwner, $temporaryPassword, $lease): mixed {
                                    $this->hook->reached(
                                        TenantProvisioningCheckpoint::BeforeOwnerProvision,
                                        Shop::query()->whereKey($centralOwner->shop_id)->firstOrFail(),
                                    );

                                    return $this->ownerProvisioner->ensureOwner(
                                        $centralOwner,
                                        $temporaryPassword,
                                        $lease,
                                    );
                                },
                            );
                            $this->safely(
                                'authorization',
                                'AUTHORIZATION_SYNC_FAILED',
                                'Tenant authorization could not be reconciled. Review the application log code and retry.',
                                function () use ($centralOwner, $owner, $lease): mixed {
                                    $this->hook->reached(
                                        TenantProvisioningCheckpoint::BeforeAuthorizationSync,
                                        Shop::query()->whereKey($centralOwner->shop_id)->firstOrFail(),
                                    );

                                    $this->syncTenantAuthorization->handle($owner, $lease);

                                    return null;
                                },
                            );
                        });

                        $this->safely(
                            'seed',
                            'TENANT_SEED_FAILED',
                            'Tenant reference data could not be installed. Review the application log code and retry.',
                            function () use ($centralOwner, $lease): mixed {
                                $this->hook->reached(
                                    TenantProvisioningCheckpoint::BeforeReferenceSeed,
                                    Shop::query()->whereKey($centralOwner->shop_id)->firstOrFail(),
                                );

                                $lease->heartbeat();
                                $this->referenceDataSeeder->run($lease);

                                return null;
                            },
                        );

                        return $migrationResult;
                    },
                ),
            );

            $this->hook->reached(
                TenantProvisioningCheckpoint::BeforeActivation,
                $freshShop->fresh(),
            );

            return $this->activate($freshShop, $migrationResult, $attempt, $lease);
        } catch (TenantProvisioningInterrupted $exception) {
            throw $exception;
        } catch (TenantProvisioningException $exception) {
            if ($exception->errorCode === 'PROVISIONING_LOCK_LOST') {
                throw $exception;
            }

            $this->recordFailure($shop, $attempt, $exception, $lease);

            throw $exception;
        } catch (Throwable) {
            $exception = TenantProvisioningException::safe(
                'database',
                'PROVISIONING_FAILED',
                'Tenant provisioning failed. Review the application log code and retry.',
            );
            $this->recordFailure($shop, $attempt, $exception, $lease);

            throw $exception;
        } finally {
            try {
                $this->connectionManager->disconnect();
            } catch (Throwable) {
                Log::error('Tenant provisioning cleanup failed.', [
                    'shop_id' => (string) $shop->getKey(),
                    'stage' => 'cleanup',
                    'error_code' => 'TENANT_CLEANUP_FAILED',
                ]);
            }
        }
    }

    private function freshProvisioningShop(#[\SensitiveParameter] Shop $shop): Shop
    {
        try {
            $freshShop = Shop::query()->whereKey($shop->getKey())->first();

            if (! $freshShop instanceof Shop) {
                throw new LogicException;
            }

            if ($freshShop->status !== ShopStatus::Provisioning) {
                throw new LogicException;
            }

            return $freshShop;
        } catch (Throwable) {
            throw TenantProvisioningException::safe(
                'target',
                'TARGET_IDENTITY_CHANGED',
                'The tenant database target changed. Review it before retrying.',
            );
        }
    }

    private function activate(
        #[\SensitiveParameter]
        Shop $shop,
        TenantMigrationResult $migrationResult,
        int $attempt,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
    ): Shop {
        try {
            return DB::connection('central')->transaction(function () use (
                $shop,
                $migrationResult,
                $attempt,
                $lease,
            ): Shop {
                $freshShop = Shop::query()
                    ->whereKey($shop->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $freshShop instanceof Shop || $freshShop->status !== ShopStatus::Provisioning) {
                    throw new LogicException;
                }

                $lease->heartbeat();
                ShopHealthSnapshot::query()->updateOrCreate(
                    ['shop_id' => $freshShop->getKey()],
                    [
                        'last_successful_connection_at' => now(),
                        'migration_status' => 'current',
                        'last_activity_at' => now(),
                        'summary' => null,
                    ],
                );
                $freshShop->markActive();
                $this->hook->reached(
                    TenantProvisioningCheckpoint::AfterActivationBeforeSuccessAudit,
                    $freshShop,
                );
                $lease->heartbeat();
                $this->recordLifecycleActivity->handle(
                    $freshShop,
                    ShopLifecycleEvent::ProvisioningSucceeded,
                    metadata: [
                        'attempt' => $attempt,
                        'database_driver' => $freshShop->database_driver,
                        'migration_batch' => $migrationResult->batch,
                        'duration_ms' => $migrationResult->durationMs,
                    ],
                );

                return $freshShop->fresh();
            });
        } catch (TenantProvisioningException $exception) {
            if ($exception->errorCode === 'PROVISIONING_LOCK_LOST') {
                throw $exception;
            }

            throw TenantProvisioningException::safe(
                'activation',
                'SHOP_ACTIVATION_FAILED',
                'The shop could not be activated. Review the application log code and retry.',
            );
        } catch (Throwable) {
            throw TenantProvisioningException::safe(
                'activation',
                'SHOP_ACTIVATION_FAILED',
                'The shop could not be activated. Review the application log code and retry.',
            );
        }
    }

    private function recordFailure(
        #[\SensitiveParameter]
        Shop $shop,
        int $attempt,
        TenantProvisioningException $exception,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
    ): void {
        $lease->heartbeat();
        Log::error('Tenant provisioning failed.', [
            'shop_id' => (string) $shop->getKey(),
            'stage' => $exception->stage,
            'error_code' => $exception->errorCode,
        ]);

        try {
            DB::connection('central')->transaction(function () use (
                $shop,
                $attempt,
                $exception,
                $lease,
            ): void {
                $freshShop = Shop::query()
                    ->whereKey($shop->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $freshShop instanceof Shop || $freshShop->status !== ShopStatus::Provisioning) {
                    return;
                }

                $lease->heartbeat();
                $freshShop->markProvisioningFailed($exception->getMessage());
                $this->recordLifecycleActivity->handle(
                    $freshShop,
                    ShopLifecycleEvent::ProvisioningFailed,
                    metadata: [
                        'attempt' => $attempt,
                        'failure_stage' => $exception->stage,
                        'error_code' => $exception->errorCode,
                    ],
                );
            });
        } catch (TenantProvisioningException $leaseException) {
            if ($leaseException->errorCode === 'PROVISIONING_LOCK_LOST') {
                throw $leaseException;
            }

            Log::error('Tenant provisioning failure state could not be recorded.', [
                'shop_id' => (string) $shop->getKey(),
                'stage' => 'activation',
                'error_code' => 'FAILURE_STATE_WRITE_FAILED',
            ]);
        } catch (Throwable) {
            Log::error('Tenant provisioning failure state could not be recorded.', [
                'shop_id' => (string) $shop->getKey(),
                'stage' => 'activation',
                'error_code' => 'FAILURE_STATE_WRITE_FAILED',
            ]);
        }
    }

    private function safely(
        string $stage,
        string $errorCode,
        string $operatorMessage,
        #[\SensitiveParameter]
        Closure $operation,
    ): mixed {
        try {
            return $operation();
        } catch (TenantProvisioningInterrupted $exception) {
            throw $exception;
        } catch (TenantProvisioningException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw TenantProvisioningException::safe($stage, $errorCode, $operatorMessage);
        }
    }
}
