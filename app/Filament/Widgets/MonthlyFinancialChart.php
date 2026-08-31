<?php

namespace App\Filament\Widgets;

use App\Enums\DashboardPeriod;
use App\Enums\Permission;
use App\Support\AdminDashboardMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class MonthlyFinancialChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = -9;

    protected ?string $heading = 'Sales, expenses & gross margin';

    protected ?string $description = 'Monthly comparison for the last 12 months';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '390px';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::ViewMargins->value) === true;
    }

    protected function getData(): array
    {
        $period = $this->period();
        $trend = (new AdminDashboardMetrics)->trend($period);

        return [
            'datasets' => [
                [
                    'label' => $period->label().' sales',
                    'data' => $trend['current']['sales'],
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.14)',
                    'tension' => 0.35,
                    'fill' => true,
                ],
                [
                    'label' => $period->label().' expenses',
                    'data' => $trend['current']['expenses'],
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.08)',
                    'tension' => 0.35,
                ],
                [
                    'label' => $period->label().' gross margin',
                    'data' => $trend['current']['margin'],
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.10)',
                    'tension' => 0.35,
                ],
                ...$this->previousDatasets($period, $trend['previous']),
            ],
            'labels' => $trend['labels'],
        ];
    }

    public function getDescription(): string
    {
        return $this->period()->label().' compared with '.$this->period()->previousLabel();
    }

    public function updatedPageFilters(): void
    {
        $this->cachedData = null;
    }

    protected function getType(): string
    {
        return 'line';
    }

    private function period(): DashboardPeriod
    {
        return DashboardPeriod::tryFrom((string) ($this->pageFilters['period'] ?? '')) ?? DashboardPeriod::Today;
    }

    /**
     * @param  array{sales: array<int, float>, expenses: array<int, float>, margin: array<int, float>}  $previous
     * @return array<int, array<string, mixed>>
     */
    private function previousDatasets(DashboardPeriod $period, array $previous): array
    {
        return collect([
            ['Sales', 'sales', '#f59e0b'],
            ['Expenses', 'expenses', '#ef4444'],
            ['Gross margin', 'margin', '#10b981'],
        ])->map(fn (array $dataset): array => [
            'label' => ucfirst($period->previousLabel()).' '.$dataset[0],
            'data' => $previous[$dataset[1]],
            'borderColor' => $dataset[2],
            'borderDash' => [6, 5],
            'borderWidth' => 1.5,
            'pointRadius' => 1,
            'tension' => 0.35,
        ])->all();
    }
}
