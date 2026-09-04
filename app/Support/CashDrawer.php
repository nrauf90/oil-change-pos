<?php

namespace App\Support;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * End-of-shift reconciliation: what came in, what went out, what should be
 * sitting in the physical drawer right now.
 *
 * Cash In is read back from sales.total_amount — what the counter actually
 * charged — and Cash Out from expenses.amount. Both are summed through
 * SaleTotalCalculator in integer paisa, so a drawer that is one paisa short is
 * reported as one paisa short and not as floating-point noise.
 *
 * A workshop's day is a few dozen rows, so the category buckets are grouped in
 * PHP rather than with database date functions: the SQL stays portable (no
 * strftime(), no DATE_FORMAT()) and every sum stays inside the integer-cent maths.
 */
final class CashDrawer
{
    private readonly Carbon $from;

    private readonly Carbon $to;

    /** @var EloquentCollection<int, Sale>|null */
    private ?EloquentCollection $sales = null;

    /** @var EloquentCollection<int, Expense>|null */
    private ?EloquentCollection $expenses = null;

    /**
     * Defaults to today — the shift the counter is standing in.
     *
     * "Today" is the shop's day, not the storage timezone's. A shop in
     * America/Chicago reconciling at 20:00 local is already past UTC midnight,
     * so measuring the boundary in UTC would drop that whole morning's takings
     * from the drawer. Boundaries are taken in the shop timezone and then
     * converted to storage for the query.
     */
    public function __construct(?Carbon $from = null, ?Carbon $to = null)
    {
        $timezone = ShopTimezone::current();

        $start = ($from ?? $to ?? Carbon::now($timezone))->copy()->setTimezone($timezone)->startOfDay();
        $end = ($to ?? $from ?? Carbon::now($timezone))->copy()->setTimezone($timezone)->endOfDay();

        // A range typed back-to-front is a slip, not an empty drawer.
        [$start, $end] = $end->lessThan($start)
            ? [$end->copy()->startOfDay(), $start->copy()->endOfDay()]
            : [$start, $end];

        $storage = ShopTimezone::storage();
        [$this->from, $this->to] = [$start->setTimezone($storage), $end->setTimezone($storage)];
    }

    /**
     * Build from `?from=` / `?to=`. Either one on its own reconciles that single
     * day; neither reconciles today.
     */
    public static function forRequest(Request $request): self
    {
        return new self(
            self::parseDate($request->query('from')),
            self::parseDate($request->query('to')),
        );
    }

    /**
     * The from / to / category filter the expense list shares with this screen.
     * Unlike the drawer itself, an unset date here means "no bound at all".
     *
     * @return array{from: ?Carbon, to: ?Carbon, category: ?string}
     */
    public static function readFilters(Request $request): array
    {
        $storage = ShopTimezone::storage();

        return [
            'from' => self::parseDate($request->query('from'))?->startOfDay()->setTimezone($storage),
            'to' => self::parseDate($request->query('to'))?->endOfDay()->setTimezone($storage),
            'category' => self::parseCategory($request->query('category')),
        ];
    }

    /**
     * Sum any pile of hand-typed amounts, exactly, in integer paisa.
     *
     * @param  iterable<mixed>  $amounts
     */
    public static function sum(iterable $amounts): string
    {
        return SaleTotalCalculator::lineSubtotal($amounts);
    }

    /** Anything unparseable is simply "no date given". */
    public static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            // A bare `2026-03-18` from the filter bar means that date at the
            // shop, not that date in the storage timezone.
            return Carbon::parse(trim($value), ShopTimezone::current());
        } catch (\Throwable) {
            return null;
        }
    }

    public function from(): Carbon
    {
        return $this->from->copy();
    }

    public function to(): Carbon
    {
        return $this->to->copy();
    }

    public function isSingleDay(): bool
    {
        return $this->from->isSameDay($this->to);
    }

    public function label(): string
    {
        return $this->isSingleDay()
            ? $this->from->format('l j F Y')
            : $this->from->format('j M Y').' – '.$this->to->format('j M Y');
    }

    /** Total of every sale finalised in the period. */
    public function cashIn(): string
    {
        return self::sum($this->sales()->pluck('total_amount'));
    }

    /** Total of every expense logged against the period. */
    public function cashOut(): string
    {
        return self::sum($this->expenses()->pluck('amount'));
    }

    /** Cash In − Cash Out. Negative when the shop spent more than it took. */
    public function net(): string
    {
        return self::sum([$this->cashIn(), self::negate($this->cashOut())]);
    }

    /** True when the drawer is down on the day — the number to print in red. */
    public function isShort(): bool
    {
        return str_starts_with($this->net(), '-');
    }

    public function saleCount(): int
    {
        return $this->sales()->count();
    }

    public function expenseCount(): int
    {
        return $this->expenses()->count();
    }

    public function hasActivity(): bool
    {
        return $this->saleCount() > 0 || $this->expenseCount() > 0;
    }

    /**
     * Where the cash went, biggest bucket first. Only categories with money in
     * them appear, and the rows sum to exactly cashOut().
     *
     * @return array<int, array{key: string, label: string, badge: string, amount: string, count: int, percent: float}>
     */
    public function breakdown(): array
    {
        $total = $this->cashOut();

        return $this->expenses()
            ->groupBy(fn (Expense $expense) => $expense->category->value)
            ->map(function (EloquentCollection $expenses, string $key) use ($total): array {
                $category = ExpenseCategory::from($key);
                $amount = self::sum($expenses->pluck('amount'));

                return [
                    'key' => $key,
                    'label' => $category->label(),
                    'badge' => $category->badgeClasses(),
                    'amount' => $amount,
                    'count' => $expenses->count(),
                    'percent' => self::share($amount, $total),
                ];
            })
            ->sortByDesc(fn (array $row) => [(float) $row['amount'], $row['count']])
            ->values()
            ->all();
    }

    /** @return EloquentCollection<int, Expense> */
    public function expenses(): EloquentCollection
    {
        return $this->expenses ??= Expense::query()
            ->between($this->from, $this->to)
            ->paidFromCash()
            ->with('user')
            ->orderByDesc('spent_at')
            ->get();
    }

    /** @return EloquentCollection<int, Sale> */
    public function sales(): EloquentCollection
    {
        return $this->sales ??= Sale::query()
            ->between($this->from, $this->to)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Everything cash-drawer/index.blade.php binds to.
     *
     * @return array<string, mixed>
     */
    public function toViewData(): array
    {
        return [
            'from' => $this->from(),
            'to' => $this->to(),
            'isSingleDay' => $this->isSingleDay(),
            'label' => $this->label(),
            'cashIn' => $this->cashIn(),
            'cashOut' => $this->cashOut(),
            'net' => $this->net(),
            'isShort' => $this->isShort(),
            'saleCount' => $this->saleCount(),
            'expenseCount' => $this->expenseCount(),
            'hasActivity' => $this->hasActivity(),
            'breakdown' => $this->breakdown(),
            'expenses' => $this->expenses(),
        ];
    }

    private static function parseCategory(mixed $value): ?string
    {
        return is_string($value) && ExpenseCategory::tryFrom($value) !== null ? $value : null;
    }

    /** Flip the sign of a canonical "0.00" string without touching a float. */
    private static function negate(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : '-'.$amount;
    }

    /** Presentation-only ratio for bar widths — never money, never a divide by zero. */
    private static function share(string $part, string $whole): float
    {
        $divisor = (float) $whole;

        return $divisor > 0.0 ? round(((float) $part / $divisor) * 100, 1) : 0.0;
    }
}
