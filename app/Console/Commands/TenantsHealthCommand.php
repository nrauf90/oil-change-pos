<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\CollectShopHealth;
use App\Enums\ShopStatus;
use App\Models\Central\Shop;
use App\Models\Central\ShopHealthSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

#[Signature('tenants:health
    {--shop= : Central shop UUID or exact slug; omit for all active and suspended shops}')]
#[Description('Refresh safe health snapshots for eligible tenant databases')]
class TenantsHealthCommand extends Command
{
    public function handle(CollectShopHealth $collectShopHealth): int
    {
        $shops = $this->requestedShops();

        if ($shops === null) {
            return self::INVALID;
        }

        $healthy = 0;
        $unhealthy = 0;

        foreach ($shops as $shop) {
            try {
                $snapshot = $collectShopHealth->handle($shop);

                if ($this->isHealthy($snapshot)) {
                    $healthy++;
                    $this->components->info($shop->slug.': healthy');
                } else {
                    $unhealthy++;
                    $this->components->warn($shop->slug.': unhealthy');
                }
            } catch (Throwable) {
                $unhealthy++;
                $this->components->error($shop->slug.': unhealthy');
            }
        }

        $this->line("Summary: healthy={$healthy} unhealthy={$unhealthy}");

        return $unhealthy === 0 ? self::SUCCESS : self::FAILURE;
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

    private function isHealthy(ShopHealthSnapshot $snapshot): bool
    {
        $summary = $snapshot->summary;

        return is_array($summary)
            && ($summary['connection_status'] ?? null) === 'healthy'
            && $snapshot->migration_status === 'current'
            && ($summary['seed_status'] ?? null) === 'current';
    }
}
