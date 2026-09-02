<?php

namespace App\Filament\Platform\Widgets;

use App\Enums\ShopStatus;
use App\Models\Central\Shop;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Route;

class ShopStatusOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected function getStats(): array
    {
        $counts = Shop::query()
            ->select('status')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->toBase()
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return [
            $this->statusStat($counts, ShopStatus::Active, 'Active', 'success', Heroicon::CheckCircle),
            $this->statusStat($counts, ShopStatus::Provisioning, 'Provisioning', 'warning', Heroicon::Clock),
            $this->statusStat($counts, ShopStatus::Failed, 'Failed', 'danger', Heroicon::ExclamationTriangle),
            $this->statusStat($counts, ShopStatus::Suspended, 'Suspended', 'gray', Heroicon::PauseCircle),
        ];
    }

    /** @return array<string, int> */
    public function getColumns(): array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    /** @param array<string, int> $counts */
    private function statusStat(
        array $counts,
        ShopStatus $status,
        string $label,
        string $color,
        Heroicon $icon,
    ): Stat {
        return Stat::make($label, $counts[$status->value] ?? 0)
            ->color($color)
            ->icon($icon)
            ->url($this->shopListUrl($status));
    }

    private function shopListUrl(ShopStatus $status): ?string
    {
        if (! Route::has('filament.platform.resources.shops.index')) {
            return null;
        }

        return route('filament.platform.resources.shops.index', [
            'tableFilters' => ['status' => ['value' => $status->value]],
        ]);
    }
}
