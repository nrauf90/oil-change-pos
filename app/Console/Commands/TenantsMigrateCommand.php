<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\RecordShopLifecycleActivity;
use App\Enums\ShopLifecycleEvent;
use App\Enums\ShopStatus;
use App\Exceptions\TenantProvisioningException;
use App\Models\Central\Shop;
use App\Models\Central\ShopHealthSnapshot;
use App\Tenancy\Migrations\TenantMigrationResult;
use App\Tenancy\Migrations\TenantMigrationRunner;
use App\Tenancy\Provisioning\TenantProvisioningLease;
use App\Tenancy\Provisioning\TenantProvisioningLock;
use App\Tenancy\TenantConnectionManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('tenants:migrate
    {--shop= : Central shop UUID or exact slug; omit for all eligible shops}
    {--force : Run without the production confirmation}')]
#[Description('Run pending canonical migrations for eligible tenant databases')]
class TenantsMigrateCommand extends Command
{
    use ConfirmableTrait;

    public function handle(
        TenantConnectionManager $connectionManager,
        TenantProvisioningLock $provisioningLock,
        TenantMigrationRunner $migrationRunner,
        RecordShopLifecycleActivity $recordLifecycleActivity,
    ): int {
        if (! $this->canProceed()) {
            return self::FAILURE;
        }

        $shops = $this->requestedShops();

        if ($shops === null) {
            return self::INVALID;
        }

        $succeeded = 0;
        $noOp = 0;
        $failed = 0;

        foreach ($shops as $shop) {
            try {
                $result = $provisioningLock->run(
                    $shop,
                    function (TenantProvisioningLease $lease) use (
                        $shop,
                        $connectionManager,
                        $migrationRunner,
                    ): TenantMigrationResult {
                        $freshShop = $this->freshEligibleShop($shop);

                        return $connectionManager->within(
                            $freshShop,
                            fn (): TenantMigrationResult => $migrationRunner->runConnected($lease),
                        );
                    },
                );
                $this->recordSuccess($shop, $result, $recordLifecycleActivity);

                if ($result->migrations === []) {
                    $noOp++;
                    $this->components->info($shop->slug.': no-op');
                } else {
                    $succeeded++;
                    $this->components->info($shop->slug.': migrated');
                }
            } catch (Throwable $exception) {
                $failed++;
                $errorCode = $this->safeErrorCode($exception);
                $this->recordFailure($shop, $errorCode, $recordLifecycleActivity);
                $this->components->error($shop->slug.': failed ['.$errorCode.']');
            } finally {
                $connectionManager->disconnect();
            }
        }

        $this->line("Summary: succeeded={$succeeded} no-op={$noOp} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function canProceed(): bool
    {
        if ($this->laravel->environment() === 'production'
            && ! $this->option('force')
            && ! $this->input->isInteractive()) {
            $this->components->error('Use --force for non-interactive production tenant migrations.');

            return false;
        }

        return $this->confirmToProceed('Tenant migrations in production');
    }

    /** @return Collection<int, Shop>|null */
    private function requestedShops(): ?Collection
    {
        $selector = $this->option('shop');
        $eligibleStatuses = [ShopStatus::Active->value, ShopStatus::Suspended->value];

        if ($selector === null) {
            return Shop::query()
                ->whereIn('status', $eligibleStatuses)
                ->orderBy('slug')
                ->orderBy('id')
                ->get();
        }

        if (! is_string($selector) || trim($selector) === '') {
            $this->components->error('The shop selector is invalid.');

            return null;
        }

        $shops = Shop::query()
            ->whereIn('status', $eligibleStatuses)
            ->where(static function (Builder $query) use ($selector): void {
                $query->whereKey($selector)->orWhere('slug', $selector);
            })
            ->limit(2)
            ->get();

        if ($shops->count() !== 1) {
            $this->components->error('The requested shop was not found or is not eligible.');

            return null;
        }

        return $shops;
    }

    private function freshEligibleShop(Shop $shop): Shop
    {
        $freshShop = Shop::query()->whereKey($shop->getKey())->first();

        if (! $freshShop instanceof Shop
            || ! in_array($freshShop->status, [ShopStatus::Active, ShopStatus::Suspended], true)) {
            throw TenantProvisioningException::safe(
                'target',
                'SHOP_NOT_ELIGIBLE',
                'The shop is no longer eligible for tenant migrations.',
            );
        }

        return $freshShop;
    }

    private function recordSuccess(
        Shop $shop,
        TenantMigrationResult $result,
        RecordShopLifecycleActivity $recordLifecycleActivity,
    ): void {
        DB::connection('central')->transaction(function () use (
            $shop,
            $result,
            $recordLifecycleActivity,
        ): void {
            $freshShop = Shop::query()->whereKey($shop->getKey())->firstOrFail();
            ShopHealthSnapshot::query()->updateOrCreate(
                ['shop_id' => $freshShop->getKey()],
                [
                    'last_successful_connection_at' => now(),
                    'migration_status' => 'current',
                ],
            );
            $recordLifecycleActivity->handle(
                $freshShop,
                ShopLifecycleEvent::MigrationSucceeded,
                metadata: [
                    'migration' => 'all',
                    'batch' => $result->batch,
                    'duration_ms' => $result->durationMs,
                ],
            );
        });
    }

    private function recordFailure(
        Shop $shop,
        string $errorCode,
        RecordShopLifecycleActivity $recordLifecycleActivity,
    ): void {
        try {
            DB::connection('central')->transaction(function () use (
                $shop,
                $errorCode,
                $recordLifecycleActivity,
            ): void {
                $freshShop = Shop::query()->whereKey($shop->getKey())->first();

                if (! $freshShop instanceof Shop) {
                    return;
                }

                ShopHealthSnapshot::query()->updateOrCreate(
                    ['shop_id' => $freshShop->getKey()],
                    ['migration_status' => 'failed'],
                );
                $recordLifecycleActivity->handle(
                    $freshShop,
                    ShopLifecycleEvent::MigrationFailed,
                    metadata: [
                        'migration' => 'all',
                        'batch' => 0,
                        'error_code' => $errorCode,
                    ],
                );
            });
        } catch (Throwable) {
        }
    }

    private function safeErrorCode(Throwable $exception): string
    {
        if ($exception instanceof TenantProvisioningException
            && preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/', $exception->errorCode) === 1) {
            return $exception->errorCode;
        }

        return 'TENANT_MIGRATION_FAILED';
    }
}
