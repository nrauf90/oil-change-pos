@php
    $report = $this->getReport();
    $rows = $report->rows();
    $money = fn (?string $amount) => $amount === null ? '—' : number_format((float) $amount, 2);
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <p class="max-w-2xl text-sm text-gray-600 dark:text-gray-400">
            What the counter actually charged, against what the shop paid. Unit cost is a purchasing
            note &mdash; it never sets a price, and this page only looks backwards at sales already made.
        </p>

        <x-filament::input.wrapper class="w-56">
            <x-filament::input.select wire:model.live="period">
                @foreach ($this->getPeriodOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    @if ($rows->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 p-12 text-center dark:border-white/10">
            <p class="font-semibold text-gray-700 dark:text-gray-300">No sales in this period</p>
            <p class="mt-1 text-sm text-gray-500">Margins appear here once invoices are recorded.</p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @php
                $cards = [
                    ['Revenue', $money($report->totalRevenue()), 'All lines in the period', 'text-gray-950 dark:text-white'],
                    ['Cost of goods', $money($report->totalCost()), 'Costed lines only', 'text-gray-950 dark:text-white'],
                    ['Gross margin', $money($report->totalMargin()), 'Costed revenue less cost',
                        str_starts_with($report->totalMargin(), '-') ? 'text-danger-600' : 'text-success-600'],
                    ['Margin %', number_format($report->overallMarginPercent(), 1).'%', 'Of costed revenue',
                        $report->overallMarginPercent() < 0 ? 'text-danger-600' : 'text-success-600'],
                ];
            @endphp

            @foreach ($cards as [$label, $value, $hint, $tone])
                <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</p>
                    <p class="mt-1 font-mono text-2xl font-bold tabular-nums {{ $tone }}">{{ $value }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        @if ($report->hasUncostedRevenue())
            <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:bg-amber-400/10 dark:text-amber-200">
                {{ $money($report->uncostedRevenue()) }} of revenue has no unit cost on file (custom lines,
                uncosted items, or items since deleted). It is excluded from the margin figures above rather
                than counted as pure profit.
            </div>
        @endif

        @if ($report->linesSoldBelowCost()->isNotEmpty())
            <div class="rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-900 dark:bg-red-400/10 dark:text-red-200">
                <strong>{{ $report->linesSoldBelowCost()->count() }}</strong>
                {{ Str::plural('item', $report->linesSoldBelowCost()->count()) }} sold below cost:
                {{ $report->linesSoldBelowCost()->pluck('item_name')->join(', ', ' and ') }}.
            </div>
        @endif

        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full min-w-3xl text-left text-sm">
                <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-3">Item</th>
                        <th class="px-4 py-3 text-center">Qty</th>
                        <th class="px-4 py-3 text-right">Charged</th>
                        <th class="px-4 py-3 text-right">Cost</th>
                        <th class="px-4 py-3 text-right">Margin</th>
                        <th class="px-4 py-3 text-right">Margin %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($rows as $row)
                        <tr @class(['bg-red-50/60 dark:bg-red-400/5' => $row['below_cost']])>
                            <td class="px-4 py-3 font-semibold text-gray-950 dark:text-white">
                                {{ $row['item_name'] }}
                                @if ($row['cost'] === null)
                                    <span class="ml-1 text-xs font-normal text-gray-400">no cost on file</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center font-mono tabular-nums">{{ $row['quantity'] }}</td>
                            <td class="px-4 py-3 text-right font-mono tabular-nums">{{ $money($row['revenue']) }}</td>
                            <td class="px-4 py-3 text-right font-mono tabular-nums text-gray-500">{{ $money($row['cost']) }}</td>
                            <td @class([
                                'px-4 py-3 text-right font-mono font-bold tabular-nums',
                                'text-danger-600' => $row['below_cost'],
                                'text-success-600' => ! $row['below_cost'] && $row['margin'] !== null,
                            ])>{{ $money($row['margin']) }}</td>
                            <td class="px-4 py-3 text-right font-mono tabular-nums text-gray-500">
                                {{ $row['cost'] === null ? '—' : number_format($row['margin_percent'], 1).'%' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
