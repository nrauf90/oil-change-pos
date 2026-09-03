<?php

namespace App\Tenancy\Migrations;

use App\Exceptions\TenantProvisioningException;
use App\Tenancy\Provisioning\TenantProvisioningCheckpoint;
use App\Tenancy\Provisioning\TenantProvisioningHook;
use App\Tenancy\Provisioning\TenantProvisioningLease;
use App\Tenancy\TenantContext;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

final readonly class TenantMigrationRunner
{
    public function __construct(
        private Migrator $migrator,
        private TenantContext $tenantContext,
        private DatabaseManager $database,
        private TenantProvisioningHook $hook,
    ) {}

    public function runConnected(
        #[\SensitiveParameter]
        TenantProvisioningLease $lease,
    ): TenantMigrationResult {
        if (! $this->tenantContext->initialized()) {
            throw TenantProvisioningException::safe(
                'migration',
                'TENANT_CONTEXT_REQUIRED',
                'Tenant migrations require an attested tenant connection.',
            );
        }

        $events = new Dispatcher;
        $events->listen(MigrationStarted::class, function (MigrationStarted $event) use ($lease): void {
            if ($event->method === 'up') {
                $lease->heartbeat();
                $this->hook->reached(
                    TenantProvisioningCheckpoint::BeforeTenantMigrationRun,
                    $this->tenantContext->shop(),
                );
                $lease->heartbeat();
            }
        });
        $events->listen(MigrationEnded::class, function (MigrationEnded $event): void {
            if ($event->method === 'up') {
                $this->hook->reached(
                    TenantProvisioningCheckpoint::AfterTenantMigrationDdlBeforeLog,
                    $this->tenantContext->shop(),
                );
            }
        });
        $migrator = new Migrator(
            $this->migrator->getRepository(),
            $this->database,
            $this->migrator->getFilesystem(),
            $events,
        );
        $migrator->setOutput(new NullOutput);

        try {
            return $migrator->usingConnection('tenant', function () use (
                $migrator,
                $lease,
            ): TenantMigrationResult {
                if (! $migrator->repositoryExists()) {
                    throw TenantProvisioningException::safe(
                        'migration',
                        'TENANT_MIGRATION_REPOSITORY_MISSING',
                        'The tenant migration repository is missing after marker installation.',
                    );
                }

                $repository = $migrator->getRepository();
                $batch = $repository->getNextBatchNumber();
                $startedAt = hrtime(true);
                $lease->heartbeat();
                $paths = $migrator->run(
                    [database_path('migrations/tenant')],
                    ['pretend' => false, 'step' => false],
                );
                $lease->heartbeat();

                return new TenantMigrationResult(
                    migrations: array_map(
                        static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
                        $paths,
                    ),
                    batch: $paths === [] ? 0 : $batch,
                    durationMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
                );
            });
        } catch (TenantProvisioningException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw TenantProvisioningException::safe(
                'migration',
                'TENANT_MIGRATION_FAILED',
                'Tenant migration failed. Review the application log code and retry.',
            );
        }
    }
}
