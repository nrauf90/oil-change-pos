<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UnitOfMeasure;
use App\Models\Item;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Quantity will be added for record to see how much Litres we sell and how
 * much left." — the shop owner.
 *
 * Two questions, and they are not the same shape. **Dispensed** is a period
 * figure: how much oil left the bottle between two dates, read from
 * `sale_items.dispensed_quantity`. **Remaining** is a running balance read from
 * `items.stock_level` — it is true *now*, not "at the end of the window", and
 * the report labels it that way rather than letting the two columns look like
 * they subtract.
 *
 * ---------------------------------------------------------------------------
 * THE RULE: a total belongs to exactly one unit
 * ---------------------------------------------------------------------------
 * Litres, kilograms and pieces are different physical quantities. 32 L plus
 * 13 kg is not 45 of anything, and a report that printed 45 would be inviting
 * the owner to reorder against a number that does not exist. So this class
 * offers no cross-unit total at all: every sum is produced *inside* a
 * {@see measuredGroups()} bucket keyed by the unit, and {@see totalsByUnit()}
 * hands back one figure per unit rather than one figure. There is deliberately
 * no `totalDispensed()` to call by mistake.
 *
 * Pieces are grouped the same way but kept out of the measured buckets
 * entirely, because "4 filters" answers a different question from "8 litres".
 *
 * ---------------------------------------------------------------------------
 * Arithmetic
 * ---------------------------------------------------------------------------
 * Quantities are not money, so SaleTotalCalculator has no business here — but
 * the same drift applies, and 0.1 + 0.2 must be 0.300. Every amount is rounded
 * once into integer thousandths (the schema's own decimal(12,3) precision),
 * summed as integers, and formatted back at the unit's precision, so a quarter
 * litre survives the round trip and nothing accumulates a float tail.
 *
 * This screen shows no price, cost, margin or total. It is a stock report; the
 * money lives on the margin report, behind a different permission.
 */
final class ConsumptionReport
{
    /** decimal(12,3) — three places is the finest amount the shop can record. */
    private const SCALE = 1000;

    /** Packs are a reorder hint, not a measurement: "1.75 Cartons" is enough. */
    private const PACK_PRECISION = 2;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $rows = null;

    public function __construct(
        private readonly ?Carbon $from = null,
        private readonly ?Carbon $to = null,
    ) {}

    /**
     * Every item in the catalogue, measured or not, one row each.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(): Collection
    {
        return $this->rows ??= $this->build();
    }

    /**
     * One bucket per measured unit that the shop actually stocks, in the order
     * the unit enum declares them. Each bucket carries its own total; nothing
     * crosses between buckets.
     *
     * @return Collection<string, array<string, mixed>>
     */
    public function measuredGroups(): Collection
    {
        $groups = collect();

        foreach (UnitOfMeasure::cases() as $unit) {
            if (! $unit->isMeasured()) {
                continue;
            }

            $rows = $this->rowsForUnit($unit);

            if ($rows->isEmpty()) {
                continue;
            }

            $total = self::amount(self::sum($rows->pluck('dispensed')), $unit);

            $groups->put($unit->value, [
                'unit' => $unit,
                'label' => $unit->label(),
                'abbreviation' => $unit->abbreviation(),
                'rows' => $rows,
                'total_dispensed' => $total,
                'total_dispensed_label' => self::labelled($total, $unit),
                'item_count' => $rows->count(),
                'low_count' => $rows->where('is_low', true)->count(),
                'negative_count' => $rows->where('is_negative', true)->count(),
            ]);
        }

        return $groups;
    }

    /**
     * Rows for one unit, biggest consumer first so the reorder list reads from
     * the top. Ties break on name, so the table never shuffles between loads.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rowsForUnit(UnitOfMeasure $unit): Collection
    {
        return $this->rows()
            ->where('unit', $unit)
            ->sort(fn (array $a, array $b) => [(float) $b['dispensed'], $a['item_name']]
                <=> [(float) $a['dispensed'], $b['item_name']])
            ->values();
    }

    /**
     * One dispensed total per measured unit — never one total.
     *
     * @return array<string, string>
     */
    public function totalsByUnit(): array
    {
        return $this->measuredGroups()
            ->map(fn (array $group) => $group['total_dispensed'])
            ->all();
    }

    /**
     * Piece-counted stock — filters, wipers. They have no `dispensed_quantity`
     * to sum, so the figure here is the line quantity: whole things off a
     * shelf. Shown in their own section rather than dropped, because the owner
     * reorders filters from the same screen, but never mixed in with litres.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pieceRows(): Collection
    {
        return $this->rowsForUnit(UnitOfMeasure::Piece);
    }

    public function hasMeasuredItems(): bool
    {
        return $this->measuredGroups()->isNotEmpty();
    }

    public function hasPieceItems(): bool
    {
        return $this->pieceRows()->isNotEmpty();
    }

    /** Said out loud next to the remaining column, so the two columns are not read as a subtraction. */
    public function remainingCaption(): string
    {
        return 'Stock on hand right now';
    }

    /* ---------------------------------------------------------------- */
    /* Building */
    /* ---------------------------------------------------------------- */

    /** @return Collection<int, array<string, mixed>> */
    private function build(): Collection
    {
        // Inactive items are included on purpose: stock you own is stock you
        // own, and a retired oil with 12 litres left still has to be used up.
        $items = Item::query()->orderBy('name')->get();

        $lines = SaleItem::query()
            // Lines whose item has been deleted keep only a name snapshot, and a
            // name cannot tell us whether it was litres or kilos — so they are
            // left out rather than guessed into the wrong unit.
            ->whereNotNull('item_id')
            ->when(
                $this->from !== null && $this->to !== null,
                fn (Builder $query) => $query->whereHas(
                    'sale',
                    fn (Builder $sale) => $sale->whereBetween('created_at', [$this->from, $this->to])
                )
            )
            ->get(['id', 'sale_id', 'item_id', 'quantity', 'dispensed_quantity'])
            ->groupBy('item_id');

        return $items
            ->map(fn (Item $item) => $this->row($item, $lines->get($item->getKey()) ?? collect()))
            ->values();
    }

    /**
     * @param  Collection<int, SaleItem>  $lines
     * @return array<string, mixed>
     */
    private function row(Item $item, Collection $lines): array
    {
        $unit = $item->unit_of_measure;

        // Measured stock is drawn down by a typed amount; pieces come off whole.
        $dispensed = $unit->isMeasured()
            ? self::sum($lines->pluck('dispensed_quantity'))
            : ((int) $lines->sum('quantity')) * self::SCALE;

        $dispensedAmount = self::amount($dispensed, $unit);

        $tracked = $item->stock_level !== null;
        $remaining = $tracked
            ? self::amount((int) round(((float) $item->stock_level) * self::SCALE), $unit)
            : null;

        [$packs, $packsLabel] = $this->packs($item, $remaining);

        return [
            'item_id' => $item->getKey(),
            'item_name' => $item->name,
            'unit' => $unit,
            'unit_label' => $unit->label(),
            'abbreviation' => $unit->abbreviation(),
            'is_measured' => $unit->isMeasured(),

            'dispensed' => $dispensedAmount,
            'dispensed_label' => self::labelled($dispensedAmount, $unit),
            'sale_count' => $lines->pluck('sale_id')->unique()->count(),

            'stock_tracked' => $tracked,
            'remaining' => $remaining,
            // "not tracked" rather than "0", because an untracked shelf is an
            // unknown, and reporting an unknown as empty would trigger a
            // reorder the shop may not need.
            'remaining_label' => $tracked ? self::labelled((string) $remaining, $unit) : 'not tracked',

            'pack_label' => $item->pack_label,
            'pack_contains' => $item->packContains(),
            'remaining_packs' => $packs,
            'remaining_packs_label' => $packsLabel,

            'is_low' => $item->isLowOnStock(),
            // Overselling is allowed on purpose, so a negative balance is not a
            // bug — it is the cue to walk out and recount the shelf.
            'is_negative' => $tracked && (float) $remaining < 0,
        ];
    }

    /**
     * The shop buys cartons and cylinders, so the number it reorders against is
     * packs, not litres. Only meaningful once the packaging is described.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function packs(Item $item, ?string $remaining): array
    {
        $contains = $item->packContains();

        if ($remaining === null || $contains === null || (float) $contains <= 0 || blank($item->pack_label)) {
            return [null, null];
        }

        $packs = number_format((float) $remaining / (float) $contains, self::PACK_PRECISION, '.', '');

        return [$packs, $packs.' '.Str::plural((string) $item->pack_label, (float) $packs === 1.0 ? 1 : 2)];
    }

    /* ---------------------------------------------------------------- */
    /* Quantity arithmetic — thousandths in, formatted decimals out */
    /* ---------------------------------------------------------------- */

    /**
     * Each amount is rounded to thousandths *before* it joins the sum, so the
     * running total is always an exact integer and never grows a float tail.
     *
     * @param  iterable<mixed>  $amounts
     */
    private static function sum(iterable $amounts): int
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += (int) round(((float) $amount) * self::SCALE);
        }

        return $total;
    }

    /** Thousandths back to a decimal string at the unit's own precision. */
    private static function amount(int $thousandths, UnitOfMeasure $unit): string
    {
        return number_format($thousandths / self::SCALE, $unit->precision(), '.', '');
    }

    /** "8.000 L", "1.500 kg", or a bare "4" for pieces. */
    private static function labelled(string $amount, UnitOfMeasure $unit): string
    {
        return trim($amount.' '.$unit->abbreviation());
    }
}
