<?php

namespace App\Filament\Widgets;

use App\Enums\DashboardPeriod;
use App\Enums\Permission;
use App\Modules\ModuleRegistry;
use App\Support\AdminDashboardMetrics;
use App\Support\SaleTotalCalculator;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TodayFinancialStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = -10;

    public function getHeading(): ?string
    {
        return $this->period()->label().' overview';
    }

    /**
     * `reports.view_margins` is contributed by ReportsModule, which is not
     * core, so the permission survives the module being switched off. Without
     * the registry check this widget keeps rendering the very figures the
     * entitlement sells after an operator disables Reporting for the shop —
     * mirrors MarginReportPage::canAccess().
     */
    public static function canView(): bool
    {
        return app(ModuleRegistry::class)->enabled('reports')
            && (auth()->user()?->can(Permission::ViewMargins->value) === true);
    }

    protected function getStats(): array
    {
        $period = $this->period();
        $comparison = (new AdminDashboardMetrics)->comparison($period);
        $metrics = $comparison['current'];
        $previous = $comparison['previous'];
        $marginDescription = $this->changeDescription($metrics['margin'], $previous['margin'], $period->previousLabel());

        if ($metrics['has_uncosted_revenue']) {
            $marginDescription .= ' · excludes uncosted sales';
        }

        return [
            Stat::make($period->label().' sales', number_format((float) $metrics['sales'], 2))
                ->description($this->changeDescription($metrics['sales'], $previous['sales'], $period->previousLabel()))
                ->descriptionIcon($this->changeIcon($metrics['sales'], $previous['sales']))
                ->color($this->changeColor($metrics['sales'], $previous['sales']))
                ->icon('heroicon-o-banknotes'),
            Stat::make($period->label().' expenses', number_format((float) $metrics['expenses'], 2))
                ->description($this->changeDescription($metrics['expenses'], $previous['expenses'], $period->previousLabel()))
                ->descriptionIcon($this->changeIcon($metrics['expenses'], $previous['expenses']))
                ->color($this->changeColor($metrics['expenses'], $previous['expenses'], lowerIsBetter: true))
                ->icon('heroicon-o-receipt-percent'),
            Stat::make($period->label().' gross margin', number_format((float) $metrics['margin'], 2))
                ->description($marginDescription)
                ->descriptionIcon($this->changeIcon($metrics['margin'], $previous['margin']))
                ->color($this->changeColor($metrics['margin'], $previous['margin']))
                ->icon('heroicon-o-chart-bar'),
        ];
    }

    private function period(): DashboardPeriod
    {
        return DashboardPeriod::tryFrom((string) ($this->pageFilters['period'] ?? '')) ?? DashboardPeriod::Today;
    }

    private function changeDescription(string $current, string $previous, string $previousLabel): string
    {
        $previousTotal = ucfirst($previousLabel).': '.number_format((float) $previous, 2);
        $difference = SaleTotalCalculator::total([$current], $this->negate($previous), null);
        $previousAmount = (float) $previous;

        if ($previousAmount === 0.0) {
            $percentage = (float) $current === 0.0 ? '0.0%' : 'new';

            return $previousTotal.' | Difference: '.$this->signedAmount($difference)." ({$percentage})";
        }

        $change = round((((float) $current - $previousAmount) / abs($previousAmount)) * 100, 1);

        return $previousTotal.' | Difference: '.$this->signedAmount($difference).' ('
            .($change >= 0 ? '+' : '').number_format($change, 1).'%)';
    }

    private function signedAmount(string $amount): string
    {
        return ((float) $amount >= 0 ? '+' : '').number_format((float) $amount, 2);
    }

    private function negate(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : '-'.$amount;
    }

    private function changeIcon(string $current, string $previous): string
    {
        return (float) $current >= (float) $previous
            ? 'heroicon-m-arrow-trending-up'
            : 'heroicon-m-arrow-trending-down';
    }

    private function changeColor(string $current, string $previous, bool $lowerIsBetter = false): string
    {
        $improved = (float) $current >= (float) $previous;

        if ($lowerIsBetter) {
            $improved = ! $improved;
        }

        return $improved ? 'success' : 'danger';
    }
}
