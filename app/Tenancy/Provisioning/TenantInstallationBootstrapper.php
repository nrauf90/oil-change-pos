<?php

namespace App\Tenancy\Provisioning;

use App\Enums\ShopLifecycleEvent;
use App\Exceptions\TenantProvisioningException;
use App\Models\Central\Shop;
use App\Models\Central\ShopLifecycleActivity;
use App\Tenancy\OpenedTenantDatabaseIdentityVerifier;
use App\Tenancy\TenantConnectionConfigurationFactory;
use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\ValidatedTenantConnection;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

final class TenantInstallationBootstrapper
{
    private const MARKER_MIGRATION = '2026_09_02_042731_create_tenant_installations_table';

    private const MARKER_COLUMNS = [
        'attestation_hmac',
        'connection_nonce',
        'created_at',
        'id',
        'shop_id',
        'target_fingerprint',
        'updated_at',
    ];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly TenantContext $tenantContext,
        private readonly TenantConnectionConfigurationFactory $configurationFactory,
        private readonly OpenedTenantDatabaseIdentityVerifier $identityVerifier,
        private readonly Migrator $migrator,
        private readonly TenantProvisioningHook $hook,
    ) {}

    public function ensureInstalled(
        #[\SensitiveParameter]
        Shop $shop,
        TenantInstallationReason $reason,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
    ): void {
        if ($this->tenantContext->initialized()) {
            throw TenantProvisioningException::safe(
                'connection',
                'TENANT_CONTEXT_ALREADY_INITIALIZED',
                'Tenant marker installation requires a clean tenant context.',
            );
        }

        $persistedShop = Shop::query()->whereKey($shop->getKey())->first();

        if (! $persistedShop instanceof Shop) {
            throw TenantProvisioningException::safe(
                'target',
                'SHOP_NOT_FOUND',
                'The shop is no longer available for provisioning.',
            );
        }

        try {
            $snapshot = $persistedShop->validatedDatabaseConnection();
        } catch (Throwable) {
            throw TenantProvisioningException::safe(
                'target',
                'TARGET_IDENTITY_CHANGED',
                'The tenant database target changed. Review it before retrying.',
            );
        }

        $connectionName = 'tenant_installer_'.str_replace('-', '', (string) Str::uuid());

        try {
            $lease->heartbeat();
            $this->config->set(
                'database.connections.'.$connectionName,
                $this->configurationFactory->make($snapshot),
            );
            $this->database->purge($connectionName);
            $connection = $this->database->connection($connectionName);
            $this->identityVerifier->openAndVerify($connection, $snapshot->target());
            $this->installOrVerifyMarker(
                $connection,
                $connectionName,
                $persistedShop,
                $snapshot,
                $reason,
                $lease,
            );
        } catch (TenantProvisioningInterrupted $exception) {
            throw $exception;
        } catch (TenantProvisioningException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw TenantProvisioningException::safe(
                'connection',
                'TENANT_MARKER_BOOTSTRAP_FAILED',
                'Tenant marker installation failed. Review the application log code and retry.',
            );
        } finally {
            try {
                $this->database->purge($connectionName);
            } catch (Throwable) {
            }

            try {
                $connections = $this->config->get('database.connections');

                if (is_array($connections)) {
                    unset($connections[$connectionName]);
                    $this->config->set('database.connections', $connections);
                }
            } catch (Throwable) {
            }

            if ($this->tenantContext->initialized()) {
                try {
                    resolve(TenantConnectionManager::class)->disconnect();
                } catch (Throwable) {
                }
            }
        }
    }

    private function installOrVerifyMarker(
        #[\SensitiveParameter]
        Connection $connection,
        string $connectionName,
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        ValidatedTenantConnection $snapshot,
        TenantInstallationReason $reason,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
    ): void {
        $events = new Dispatcher;
        $events->listen(MigrationStarted::class, function (MigrationStarted $event) use ($lease): void {
            if ($event->name === self::MARKER_MIGRATION && $event->method === 'up') {
                $lease->heartbeat();
            }
        });
        $events->listen(MigrationEnded::class, function (MigrationEnded $event) use (
            $shop,
            $lease,
        ): void {
            if ($event->name === self::MARKER_MIGRATION && $event->method === 'up') {
                $this->hook->reached(
                    TenantProvisioningCheckpoint::AfterMarkerTableDdlBeforeLog,
                    $shop,
                );
                $lease->heartbeat();
            }
        });
        $migrator = new Migrator(
            $this->migrator->getRepository(),
            $this->database,
            $this->migrator->getFilesystem(),
            $events,
        );
        $migrator->setOutput(new NullOutput);

        $migrator->usingConnection($connectionName, function () use (
            $connection,
            $migrator,
            $shop,
            $snapshot,
            $reason,
            $lease,
        ): void {
            $schema = $connection->getSchemaBuilder();
            $repositoryExists = $migrator->repositoryExists();
            $tableExists = $schema->hasTable('tenant_installations');
            $receiptMatches = $this->hasMatchingAuthorizationReceipt($shop, $snapshot, $reason);

            if ($repositoryExists) {
                $migrationRows = $connection->table('migrations')
                    ->where('migration', self::MARKER_MIGRATION)
                    ->count();

                if ($migrationRows > 1) {
                    throw $this->markerConflict();
                }
            } else {
                $migrationRows = 0;
            }

            if ($migrationRows === 1 && ! $tableExists) {
                throw TenantProvisioningException::safe(
                    'connection',
                    'TENANT_MARKER_INCONSISTENT',
                    'The tenant marker state is inconsistent and requires operator review.',
                );
            }

            $markerMatches = false;
            $markerMissing = true;

            if ($tableExists) {
                $this->assertExactMarkerSchema($connection);
                [$markerMatches, $markerMissing] = $this->inspectMarkerRow($connection, $shop, $snapshot);
            }

            if ($markerMatches && $migrationRows === 1) {
                return;
            }

            if (! $markerMatches && ! $receiptMatches) {
                throw TenantProvisioningException::safe(
                    'database',
                    'TARGET_ALREADY_EXISTS',
                    'The tenant database already exists without matching provisioning authorization.',
                );
            }

            if (! $repositoryExists) {
                if ($reason !== TenantInstallationReason::ExclusiveCreate) {
                    throw TenantProvisioningException::safe(
                        'connection',
                        'TENANT_MARKER_INCONSISTENT',
                        'The adopted tenant database has no migration repository.',
                    );
                }

                $lease->heartbeat();
                $migrator->getRepository()->createRepository();
            }

            if (! $tableExists) {
                $migrator->run([$this->markerMigrationPath()], [
                    'pretend' => false,
                    'step' => false,
                ]);
                $this->assertExactMarkerSchema($connection);
            } elseif ($migrationRows === 0) {
                $lease->heartbeat();
                $migrator->getRepository()->log(
                    self::MARKER_MIGRATION,
                    $migrator->getRepository()->getNextBatchNumber(),
                );
            }

            if ($markerMissing) {
                $this->hook->reached(
                    TenantProvisioningCheckpoint::AfterMarkerMigrationLoggedBeforeRow,
                    $shop,
                );
                $lease->heartbeat();
                $this->insertMarkerRow($connection, $shop, $snapshot, $lease);
                $this->hook->reached(
                    TenantProvisioningCheckpoint::AfterMarkerRowBeforeManagerConnection,
                    $shop,
                );
                $lease->heartbeat();
            }

            [$markerMatches] = $this->inspectMarkerRow($connection, $shop, $snapshot);

            if (! $markerMatches) {
                throw $this->markerConflict();
            }
        });
    }

    private function assertExactMarkerSchema(#[\SensitiveParameter] Connection $connection): void
    {
        $columns = array_column(
            $connection->getSchemaBuilder()->getColumns('tenant_installations'),
            'name',
        );
        sort($columns, SORT_STRING);

        if ($columns !== self::MARKER_COLUMNS) {
            throw $this->markerConflict();
        }

        $hasPrimaryKey = false;
        $hasUniqueShopId = false;

        foreach ($connection->getSchemaBuilder()->getIndexes('tenant_installations') as $index) {
            $indexColumns = $index['columns'] ?? [];

            if (($index['primary'] ?? false) === true && $indexColumns === ['id']) {
                $hasPrimaryKey = true;
            }

            if (($index['unique'] ?? false) === true && $indexColumns === ['shop_id']) {
                $hasUniqueShopId = true;
            }
        }

        if (! $hasPrimaryKey || ! $hasUniqueShopId) {
            throw $this->markerConflict();
        }
    }

    /** @return array{bool, bool} */
    private function inspectMarkerRow(
        #[\SensitiveParameter]
        Connection $connection,
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        ValidatedTenantConnection $snapshot,
    ): array {
        $rows = $connection->table('tenant_installations')
            ->select(['id', 'shop_id', 'target_fingerprint', 'attestation_hmac', 'connection_nonce'])
            ->limit(2)
            ->get();

        if ($rows->isEmpty()) {
            return [false, true];
        }

        if ($rows->count() !== 1) {
            throw $this->markerConflict();
        }

        $marker = $rows->first();

        if (! is_object($marker)
            || (int) ($marker->id ?? 0) !== 1
            || ! is_string($marker->shop_id ?? null)
            || ! is_string($marker->target_fingerprint ?? null)
            || ! is_string($marker->attestation_hmac ?? null)
            || ! hash_equals((string) $shop->getKey(), $marker->shop_id)
            || ! hash_equals($snapshot->target()->fingerprint, $marker->target_fingerprint)
            || ! hash_equals($snapshot->expectedMarkerHmac(), $marker->attestation_hmac)
            || (! is_null($marker->connection_nonce) && ! is_string($marker->connection_nonce))) {
            throw $this->markerConflict();
        }

        return [true, false];
    }

    private function insertMarkerRow(
        #[\SensitiveParameter]
        Connection $connection,
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        ValidatedTenantConnection $snapshot,
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
    ): void {
        $connection->transaction(function () use ($connection, $shop, $snapshot, $lease): void {
            $lease->heartbeat();
            $connection->table('tenant_installations')->insert([
                'id' => 1,
                'shop_id' => $shop->getKey(),
                'target_fingerprint' => $snapshot->target()->fingerprint,
                'attestation_hmac' => $snapshot->expectedMarkerHmac(),
                'connection_nonce' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function hasMatchingAuthorizationReceipt(
        #[\SensitiveParameter]
        Shop $shop,
        #[\SensitiveParameter]
        ValidatedTenantConnection $snapshot,
        TenantInstallationReason $reason,
    ): bool {
        $activities = ShopLifecycleActivity::query()
            ->where('shop_id', $shop->getKey())
            ->where('event', ShopLifecycleEvent::TenantInstallationAuthorized->value)
            ->latest('occurred_at')
            ->get(['metadata']);

        foreach ($activities as $activity) {
            $metadata = $activity->metadata;

            if (! is_array($metadata)
                || ($metadata['database_driver'] ?? null) !== $snapshot->target()->driver
                || ($metadata['reason_code'] ?? null) !== $reason->value
                || ! is_string($metadata['target_fingerprint'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $metadata['target_fingerprint']) !== 1) {
                continue;
            }

            if (hash_equals($snapshot->target()->fingerprint, $metadata['target_fingerprint'])) {
                return true;
            }
        }

        return false;
    }

    private function markerMigrationPath(): string
    {
        return database_path('migrations/tenant/'.self::MARKER_MIGRATION.'.php');
    }

    private function markerConflict(): TenantProvisioningException
    {
        return TenantProvisioningException::safe(
            'connection',
            'TENANT_MARKER_CONFLICT',
            'The tenant marker conflicts with the registered shop and requires operator review.',
        );
    }
}
