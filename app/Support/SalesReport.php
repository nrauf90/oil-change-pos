<?php

namespace App\Support;

use App\Enums\SaleLineType;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * Everything the reporting dashboard knows how to count.
 *
 * Every figure here is read back from what was actually charged and stored —
 * sales.total_amount, sales.labor_charge, sales.misc_charge and
 * sale_items.manually_charged_price. Item::$unit_cost is a purchasing note
 * for the counter, never revenue, and is deliberately never touched by this class.
 *
 * The window is small (one workshop, a few hundred sales a year), so the buckets
 * are grouped in PHP rather than with database date functions. That keeps the
 * money summing inside SaleTotalCalculator's integer-cent maths and keeps the SQL
 * portable — no strftime(), no DATE_FORMAT().
 */
final class SalesReport
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    /** How many buckets each period draws, newest last. */
    private const BUCKETS = [
        self::DAILY => 14,
        self::WEEKLY => 12,
        self::MONTHLY => 12,
    ];

    private const PERIOD_LABELS = [
        self::DAILY => 'Daily',
        self::WEEKLY => 'Weekly',
        self::MONTHLY => 'Monthly',
    ];

    private const TOP_ITEM_LIMIT = 10;

    private readonly string $period;

    private readonly Carbon $now;

    /** @var EloquentCollection<int, Sale>|null */
    private ?EloquentCollection $sales = null;

    public function __construct(?string $period = null, ?Carbon $now = null)
    {
        $this->period = self::normalisePeriod($period);
        // Headline cards bound the shop's day / week / month, so the clock is
        // read in the shop's timezone; the card() helper converts each
        // boundary back to storage for the query.
        $this->now = ($now ?? Carbon::now())->copy()->setTimezone(ShopTimezone::current());
    }

    /** Anything unrecognised quietly becomes the daily default. */
    public static function normalisePeriod(?string $period): string
    {
        return array_key_exists((string) $period, self::BUCKETS) ? (string) $period : self::DAILY;
    }

    /** @return array<string, string> */
    public static function periods(): array
    {
        return self::PERIOD_LABELS;
    }

    public function period(): string
    {
        return $this->period;
    }

    public function hasSales(): bool
    {
        return Sale::query()->exists();
    }

    /**
     * The three always-on cards, each over its own calendar window.
     *
     * @return array<string, array{label: string, range: string, revenue: string, count: int}>
     */
    public function headline(): array
    {
        return [
            'today' => $this->card(
                'Today',
                $this->now->copy()->startOfDay(),
                $this->now->copy()->endOfDay(),
                $this->now->format('D j M Y'),
            ),
            'week' => $this->card(
                'This week',
                $weekStart = $this->now->copy()->startOfWeek(),
                $this->now->copy()->endOfWeek(),
                $weekStart->format('j M').' – '.$this->now->copy()->endOfWeek()->format('j M'),
            ),
            'month' => $this->card(
                'This month',
                $this->now->copy()->startOfMonth(),
                $this->now->copy()->endOfMonth(),
                $this->now->format('F Y'),
            ),
        ];
    }

    /**
     * A gap-free series over the selected period. Quiet buckets report zero
     * rather than vanishing, so the chart never lies by omission.
     *
     * @return array<int, array{key: string, label: string, count: int, revenue: string, percent: float}>
     */
    public function breakdown(): array
    {
        $grouped = $this->windowSales()->groupBy(fn (Sale $sale) => $this->bucketKey($sale->created_at));

        $rows = [];

        foreach ($this->bucketStarts() as $start) {
            $key = $this->bucketKey($start);
            $sales = $grouped->get($key) ?? new EloquentCollection;

            $rows[] = [
                'key' => $key,
                'label' => $this->bucketLabel($start),
                'count' => $sales->count(),
                'revenue' => SaleTotalCalculator::lineSubtotal($sales->pluck('total_amount')),
                'percent' => 0.0,
            ];
        }

        $peak = max(array_map(static fn (array $row) => (float) $row['revenue'], $rows) ?: [0.0]);

        return array_map(
            static fn (array $row) => [...$row, 'percent' => self::share($row['revenue'], (string) $peak)],
            $rows,
        );
    }

    /**
     * Where the window's money came from. The five rows reconcile exactly with
     * windowRevenue(), because a sale total is its lines plus labor plus misc.
     *
     * @return array<int, array{key: string, label: string, amount: string, percent: float}>
     */
    public function split(): array
    {
        $sales = $this->windowSales();
        $lines = $sales->flatMap(fn (Sale $sale) => $sale->lines);

        $byType = fn (SaleLineType $type) => SaleTotalCalculator::lineSubtotal(
            $lines->where('type', $type)->pluck('manually_charged_price')
        );

        $amounts = [
            'product' => ['Product lines', $byType(SaleLineType::Product)],
            'repair' => ['Repair lines', $byType(SaleLineType::Repair)],
            'custom' => ['Custom lines', $byType(SaleLineType::Custom)],
            'labor' => ['Labor charge', SaleTotalCalculator::lineSubtotal($sales->pluck('labor_charge'))],
            'misc' => ['Misc charge', SaleTotalCalculator::lineSubtotal($sales->pluck('misc_charge'))],
        ];

        $total = $this->windowRevenue();

        return array_map(
            static fn (string $key, array $row) => [
                'key' => $key,
                'label' => $row[0],
                'amount' => $row[1],
                'percent' => self::share($row[1], $total),
            ],
            array_keys($amounts),
            array_values($amounts),
        );
    }

    /**
     * Most frequently charged line names in the window, by times charged.
     *
     * Grouped on the stored item_name snapshot, so items deleted from inventory
     * still show up under the name they were sold as.
     *
     * @return array<int, array{item_name: string, times_charged: int, revenue: string, percent: float}>
     */
    public function topItems(): array
    {
        $rows = $this->windowSales()
            ->flatMap(fn (Sale $sale) => $sale->lines)
            ->groupBy(fn (SaleItem $line) => (string) $line->item_name)
            ->map(fn ($lines, string $name) => [
                'item_name' => $name,
                'times_charged' => $lines->count(),
                'revenue' => SaleTotalCalculator::lineSubtotal($lines->pluck('manually_charged_price')),
            ])
            ->sortByDesc(fn (array $row) => [$row['times_charged'], (float) $row['revenue']])
            ->take(self::TOP_ITEM_LIMIT)
            ->values()
            ->all();

        $peak = max(array_map(static fn (array $row) => $row['times_charged'], $rows) ?: [0]);

        return array_map(
            static fn (array $row) => [
                ...$row,
                'percent' => self::share((string) $row['times_charged'], (string) $peak),
            ],
            $rows,
        );
    }

    /** Revenue actually charged across the whole selected window. */
    public function windowRevenue(): string
    {
        return SaleTotalCalculator::lineSubtotal($this->windowSales()->pluck('total_amount'));
    }

    public function windowSaleCount(): int
    {
        return $this->windowSales()->count();
    }

    /** What one bucket is called, for the breakdown table header. */
    public function bucketNoun(): string
    {
        return match ($this->period) {
            self::WEEKLY => 'Week',
            self::MONTHLY => 'Month',
            default => 'Day',
        };
    }

    public function windowLabel(): string
    {
        return match ($this->period) {
            self::WEEKLY => 'Last '.self::BUCKETS[self::WEEKLY].' weeks',
            self::MONTHLY => 'Last '.self::BUCKETS[self::MONTHLY].' months',
            default => 'Last '.self::BUCKETS[self::DAILY].' days',
        };
    }

    /** Everything reports/index.blade.php binds to. */
    public function toViewData(): array
    {
        return [
            'period' => $this->period,
            'periods' => self::periods(),
            'hasSales' => $this->hasSales(),
            'headline' => $this->headline(),
            'breakdown' => $this->breakdown(),
            'split' => $this->split(),
            'topItems' => $this->topItems(),
            'windowRevenue' => $this->windowRevenue(),
            'windowSaleCount' => $this->windowSaleCount(),
            'windowLabel' => $this->windowLabel(),
            'bucketNoun' => $this->bucketNoun(),
        ];
    }

    /** @return array{label: string, range: string, revenue: string, count: int} */
    private function card(string $label, Carbon $from, Carbon $to, string $range): array
    {
        $storage = ShopTimezone::storage();
        $totals = Sale::query()
            ->between($from->copy()->setTimezone($storage), $to->copy()->setTimezone($storage))
            ->pluck('total_amount');

        return [
            'label' => $label,
            'range' => $range,
            'revenue' => SaleTotalCalculator::lineSubtotal($totals),
            'count' => $totals->count(),
        ];
    }

    /** @return EloquentCollection<int, Sale> */
    private function windowSales(): EloquentCollection
    {
        return $this->sales ??= Sale::query()
            ->between($this->windowStart(), $this->windowEnd())
            ->with('lines')
            ->get();
    }

    private function windowStart(): Carbon
    {
        $back = self::BUCKETS[$this->period] - 1;

        return match ($this->period) {
            self::WEEKLY => $this->now->copy()->startOfWeek()->subWeeks($back),
            self::MONTHLY => $this->now->copy()->startOfMonth()->subMonths($back),
            default => $this->now->copy()->startOfDay()->subDays($back),
        };
    }

    private function windowEnd(): Carbon
    {
        return match ($this->period) {
            self::WEEKLY => $this->now->copy()->endOfWeek(),
            self::MONTHLY => $this->now->copy()->endOfMonth(),
            default => $this->now->copy()->endOfDay(),
        };
    }

    /** @return array<int, Carbon> */
    private function bucketStarts(): array
    {
        $start = $this->windowStart();

        return array_map(fn (int $offset) => match ($this->period) {
            self::WEEKLY => $start->copy()->addWeeks($offset),
            self::MONTHLY => $start->copy()->addMonths($offset),
            default => $start->copy()->addDays($offset),
        }, range(0, self::BUCKETS[$this->period] - 1));
    }

    private function bucketKey(Carbon $at): string
    {
        return match ($this->period) {
            self::WEEKLY => $at->copy()->startOfWeek()->format('Y-m-d'),
            self::MONTHLY => $at->format('Y-m'),
            default => $at->format('Y-m-d'),
        };
    }

    private function bucketLabel(Carbon $start): string
    {
        return match ($this->period) {
            self::WEEKLY => 'w/c '.$start->format('j M'),
            self::MONTHLY => $start->format('M Y'),
            default => $start->format('D j M'),
        };
    }

    /** Presentation-only ratio for bar widths — never money. */
    private static function share(string $part, string $whole): float
    {
        $divisor = (float) $whole;

        return $divisor > 0.0 ? round(((float) $part / $divisor) * 100, 1) : 0.0;
    }
}
