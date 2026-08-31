<?php

namespace App\Support;

use App\Models\SaleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Profit margin analysis (PRD §2): what the counter actually charged, against
 * what the shop paid for the part.
 *
 * The direction of that comparison matters. `unit_cost` is a purchasing note and
 * never sets a price — this report reads it only to look *backwards* at sales
 * that already happened. Nothing here can influence a bill.
 *
 * Where no cost is on file (a custom line, an uncosted item, an item since
 * deleted) the revenue is still counted but the margin is reported as unknown
 * rather than as zero — pretending an unknown cost is zero would overstate
 * profit, which is the one direction a shop owner must never be misled in.
 */
class MarginReport
{
    private ?Collection $rows = null;

    public function __construct(
        private readonly ?Carbon $from = null,
        private readonly ?Carbon $to = null,
    ) {}

    /**
     * @return Collection<int, array{
     *     item_id: ?int, item_name: string, quantity: int, revenue: string,
     *     cost: ?string, margin: ?string, margin_percent: float, below_cost: bool
     * }>
     */
    public function rows(): Collection
    {
        return $this->rows ??= $this->build();
    }

    public function totalRevenue(): string
    {
        return SaleTotalCalculator::lineSubtotal($this->rows()->pluck('revenue'));
    }

    /** Revenue from lines we can actually cost — the only honest margin base. */
    public function costedRevenue(): string
    {
        return SaleTotalCalculator::lineSubtotal(
            $this->rows()->where('cost', '!==', null)->pluck('revenue')
        );
    }

    public function uncostedRevenue(): string
    {
        return SaleTotalCalculator::lineSubtotal(
            $this->rows()->where('cost', null)->pluck('revenue')
        );
    }

    public function totalCost(): string
    {
        return SaleTotalCalculator::lineSubtotal($this->rows()->pluck('cost')->filter());
    }

    public function totalMargin(): string
    {
        return SaleTotalCalculator::total([$this->costedRevenue()], $this->negate($this->totalCost()), null);
    }

    /** Margin as a percentage of costed revenue, not of total revenue. */
    public function overallMarginPercent(): float
    {
        return $this->percentOf($this->totalMargin(), $this->costedRevenue());
    }

    /** @return Collection<int, array<string, mixed>> */
    public function linesSoldBelowCost(): Collection
    {
        return $this->rows()->where('below_cost', true)->values();
    }

    public function hasUncostedRevenue(): bool
    {
        return $this->uncostedRevenue() !== '0.00';
    }

    /** @return Collection<int, array<string, mixed>> */
    private function build(): Collection
    {
        $lines = SaleItem::query()
            ->with('item:id,unit_cost')
            ->when(
                $this->from && $this->to,
                fn ($query) => $query->whereHas(
                    'sale',
                    fn ($sale) => $sale->whereBetween('created_at', [$this->from, $this->to])
                )
            )
            ->get();

        return $lines
            // Identity is the inventory item, never the printed name. Grouping by
            // name let one costed line lend its cost to a same-named custom line,
            // dragging that line's revenue into the costed base and overstating
            // profit. Lines with no item left — custom lines, and items since
            // deleted — have nothing but their name to be told apart by, and are
            // never costed, so they bucket on the name alone.
            ->groupBy(fn (SaleItem $line) => $line->item_id === null
                ? 'name:'.$line->item_name
                : 'item:'.$line->item_id)
            ->map(function (Collection $group): array {
                /** @var SaleItem $first */
                $first = $group->first();

                $quantity = (int) $group->sum('quantity');
                $revenue = SaleTotalCalculator::lineSubtotal($group->pluck('manually_charged_price'));

                // One group is one inventory item, so its unit cost is either known
                // for every line or for none — there is no half-costed row to fudge.
                $unitCost = $first->item?->unit_cost;

                $costTotal = $unitCost === null
                    ? null
                    : $this->multiply((string) $unitCost, $quantity);

                $margin = $costTotal === null
                    ? null
                    : SaleTotalCalculator::total([$revenue], $this->negate($costTotal), null);

                return [
                    'item_id' => $first->item_id,
                    'item_name' => $first->item_name,
                    'quantity' => $quantity,
                    'revenue' => $revenue,
                    'cost' => $costTotal,
                    'margin' => $margin,
                    'margin_percent' => $margin === null ? 0.0 : $this->percentOf($margin, $revenue),
                    'below_cost' => $margin !== null && str_starts_with($margin, '-'),
                ];
            })
            ->sortByDesc(fn (array $row) => (float) $row['revenue'])
            ->values();
    }

    /** Repeated addition, so the multiply stays inside integer-cent maths. */
    private function multiply(string $amount, int $times): string
    {
        return SaleTotalCalculator::lineSubtotal(array_fill(0, max($times, 0), $amount));
    }

    private function negate(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : '-'.$amount;
    }

    private function percentOf(string $part, string $whole): float
    {
        $divisor = (float) $whole;

        return $divisor === 0.0 ? 0.0 : round(((float) $part / $divisor) * 100, 2);
    }
}
