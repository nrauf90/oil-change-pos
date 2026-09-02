<?php

namespace App\Support;

use App\Enums\DashboardPeriod;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AdminDashboardMetrics
{
    /**
     * @return array{current: array<string, mixed>, previous: array<string, mixed>}
     */
    public function comparison(DashboardPeriod $period, ?string $timezone = null): array
    {
        return [
            'current' => $this->metricsFor(...$this->rangeInStorageTimezone(
                $period->currentRange($timezone),
            )),
            'previous' => $this->metricsFor(...$this->rangeInStorageTimezone(
                $period->previousRange($timezone),
            )),
        ];
    }

    /**
     * @return array{
     *   labels: array<int, string>, current: array{sales: array<int, float>, expenses: array<int, float>, margin: array<int, float>},
     *   previous: array{sales: array<int, float>, expenses: array<int, float>, margin: array<int, float>}
     * }
     */
    public function trend(DashboardPeriod $period): array
    {
        [$currentFrom, $currentTo] = $period->currentRange();
        [$previousFrom, $previousTo] = $period->previousRange();
        $bucketCount = count($period->chartLabels());
        $sales = Sale::between($previousFrom, $currentTo)->get(['id', 'created_at', 'total_amount']);
        $expenses = Expense::between($previousFrom, $currentTo)->get(['id', 'spent_at', 'amount']);
        $lines = SaleItem::query()
            ->select(['id', 'sale_id', 'item_id', 'quantity', 'manually_charged_price'])
            ->with(['sale:id,created_at', 'item:id,unit_cost'])
            ->whereHas('sale', fn ($query) => $query->between($previousFrom, $currentTo))
            ->get();

        return [
            'labels' => $period->chartLabels(),
            'current' => $this->bucketedMetrics($period, $bucketCount, $sales, $expenses, $lines, $currentFrom, $currentTo),
            'previous' => $this->bucketedMetrics($period, $bucketCount, $sales, $expenses, $lines, $previousFrom, $previousTo),
        ];
    }

    /**
     * @return array{
     *     sales: string, sale_count: int, expenses: string, expense_count: int,
     *     margin: string, margin_percent: float, has_uncosted_revenue: bool
     * }
     */
    public function today(): array
    {
        $from = now()->startOfDay();
        $to = now()->endOfDay();
        $sales = Sale::between($from, $to)->get(['id', 'total_amount']);
        $expenses = Expense::between($from, $to)->get(['id', 'amount']);
        $margin = new MarginReport($from, $to);

        return [
            'sales' => SaleTotalCalculator::lineSubtotal($sales->pluck('total_amount')),
            'sale_count' => $sales->count(),
            'expenses' => SaleTotalCalculator::lineSubtotal($expenses->pluck('amount')),
            'expense_count' => $expenses->count(),
            'margin' => $margin->totalMargin(),
            'margin_percent' => $margin->overallMarginPercent(),
            'has_uncosted_revenue' => $margin->hasUncostedRevenue(),
        ];
    }

    /**
     * @return array{sales: string, sale_count: int, expenses: string, expense_count: int, margin: string, margin_percent: float, has_uncosted_revenue: bool}
     */
    private function metricsFor(Carbon $from, Carbon $to): array
    {
        $sales = Sale::between($from, $to)->get(['id', 'total_amount']);
        $expenses = Expense::between($from, $to)->get(['id', 'amount']);
        $margin = new MarginReport($from, $to);

        return [
            'sales' => SaleTotalCalculator::lineSubtotal($sales->pluck('total_amount')),
            'sale_count' => $sales->count(),
            'expenses' => SaleTotalCalculator::lineSubtotal($expenses->pluck('amount')),
            'expense_count' => $expenses->count(),
            'margin' => $margin->totalMargin(),
            'margin_percent' => $margin->overallMarginPercent(),
            'has_uncosted_revenue' => $margin->hasUncostedRevenue(),
        ];
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}  $range
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rangeInStorageTimezone(array $range): array
    {
        $storageTimezone = (string) config('app.timezone');

        return [
            $range[0]->setTimezone($storageTimezone),
            $range[1]->setTimezone($storageTimezone),
        ];
    }

    /**
     * @param  Collection<int, Sale>  $sales
     * @param  Collection<int, Expense>  $expenses
     * @param  Collection<int, SaleItem>  $lines
     * @return array{sales: array<int, float>, expenses: array<int, float>, margin: array<int, float>}
     */
    private function bucketedMetrics(
        DashboardPeriod $period,
        int $bucketCount,
        Collection $sales,
        Collection $expenses,
        Collection $lines,
        Carbon $from,
        Carbon $to,
    ): array {
        $emptyBuckets = array_fill(0, $bucketCount, '0.00');
        $saleBuckets = $emptyBuckets;
        $expenseBuckets = $emptyBuckets;
        $marginBuckets = $emptyBuckets;

        foreach ($sales->filter(fn (Sale $sale): bool => $sale->created_at->betweenIncluded($from, $to))->groupBy(fn (Sale $sale): int => $period->bucket($sale->created_at)) as $bucket => $records) {
            if ($bucket < $bucketCount) {
                $saleBuckets[$bucket] = SaleTotalCalculator::lineSubtotal($records->pluck('total_amount'));
            }
        }

        foreach ($expenses->filter(fn (Expense $expense): bool => $expense->spent_at->betweenIncluded($from, $to))->groupBy(fn (Expense $expense): int => $period->bucket($expense->spent_at)) as $bucket => $records) {
            if ($bucket < $bucketCount) {
                $expenseBuckets[$bucket] = SaleTotalCalculator::lineSubtotal($records->pluck('amount'));
            }
        }

        foreach ($lines->filter(fn (SaleItem $line): bool => $line->sale->created_at->betweenIncluded($from, $to))->groupBy(fn (SaleItem $line): int => $period->bucket($line->sale->created_at)) as $bucket => $records) {
            if ($bucket < $bucketCount) {
                $marginBuckets[$bucket] = $this->marginFor($records);
            }
        }

        return [
            'sales' => array_map('floatval', $saleBuckets),
            'expenses' => array_map('floatval', $expenseBuckets),
            'margin' => array_map('floatval', $marginBuckets),
        ];
    }

    /**
     * @return array<int, array{label: string, sales: string, expenses: string, margin: string}>
     */
    public function monthly(): array
    {
        $firstMonth = now()->startOfMonth()->subMonths(11);
        $lastMonth = now()->endOfMonth();

        $sales = Sale::between($firstMonth, $lastMonth)
            ->get(['id', 'created_at', 'total_amount'])
            ->groupBy(fn (Sale $sale): string => $sale->created_at->format('Y-m'));

        $expenses = Expense::between($firstMonth, $lastMonth)
            ->get(['id', 'spent_at', 'amount'])
            ->groupBy(fn (Expense $expense): string => $expense->spent_at->format('Y-m'));

        $saleLines = SaleItem::query()
            ->select(['id', 'sale_id', 'item_id', 'quantity', 'manually_charged_price'])
            ->with(['sale:id,created_at', 'item:id,unit_cost'])
            ->whereHas('sale', fn ($query) => $query->between($firstMonth, $lastMonth))
            ->get()
            ->groupBy(fn (SaleItem $line): string => $line->sale->created_at->format('Y-m'));

        return collect(range(0, 11))->map(function (int $offset) use ($firstMonth, $sales, $expenses, $saleLines): array {
            $month = $firstMonth->copy()->addMonths($offset);
            $key = $month->format('Y-m');

            return [
                'label' => $month->format('M Y'),
                'sales' => SaleTotalCalculator::lineSubtotal($sales->get($key, collect())->pluck('total_amount')),
                'expenses' => SaleTotalCalculator::lineSubtotal($expenses->get($key, collect())->pluck('amount')),
                'margin' => $this->marginFor($saleLines->get($key, collect())),
            ];
        })->all();
    }

    /** @param Collection<int, SaleItem> $lines */
    private function marginFor(Collection $lines): string
    {
        $costedLines = $lines->filter(fn (SaleItem $line): bool => $line->item?->unit_cost !== null);
        $revenue = SaleTotalCalculator::lineSubtotal($costedLines->pluck('manually_charged_price'));
        $cost = SaleTotalCalculator::lineSubtotal(
            $costedLines->flatMap(fn (SaleItem $line): array => array_fill(
                0,
                max($line->quantity, 0),
                (string) $line->item->unit_cost,
            )),
        );

        return SaleTotalCalculator::total([$revenue], $this->negate($cost), null);
    }

    private function negate(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : '-'.$amount;
    }
}
