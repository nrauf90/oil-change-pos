@php
    /**
     * Two questions on one screen, kept visibly apart: how much went out over
     * the chosen period, and how much is on the shelf right now. Each unit gets
     * its own table and its own total — litres and kilograms are never added
     * together, because 32 L + 13 kg is not 45 of anything.
     *
     * No price, cost, margin or total appears here. It is a stock screen.
     */
    $report = $this->getReport();
    $groups = $report->measuredGroups();
    $pieces = $report->pieceRows();
    $periodLabel = strtolower($this->getPeriodLabel());
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <p class="max-w-2xl text-sm text-gray-600 dark:text-gray-400">
            How much oil and gas went out, and how much is left. <strong>Dispensed</strong> covers the
            selected period; <strong>{{ $report->remainingCaption() }}</strong> is today's balance, not
            the period's closing figure &mdash; the two columns do not subtract.
        </p>

        <x-filament::input.wrapper class="w-56">
            <x-filament::input.select wire:model.live="period">
                @foreach ($this->getPeriodOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    @if ($groups->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 p-12 text-center dark:border-white/10">
            <p class="font-semibold text-gray-700 dark:text-gray-300">
                No items are set up in litres or kilograms yet
            </p>
            <p class="mx-auto mt-2 max-w-lg text-sm text-gray-500">
                This page counts bulk consumables &mdash; oil poured by the litre, AC gas charged by
                the kilogram. Open an item in Inventory and set its unit of measure to Litres or
                Kilograms, and its consumption will start showing up here.
            </p>
        </div>
    @endif

    @foreach ($groups as $group)
        <section class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="text-lg font-bold text-gray-950 dark:text-white">
                    {{ $group['label'] }}
                    <span class="ml-1 text-sm font-normal text-gray-500">
                        {{ $group['item_count'] }} {{ Str::plural('item', $group['item_count']) }}
                    </span>
                </h2>

                <p class="text-sm text-gray-500">
                    Dispensed {{ $periodLabel }}:
                    <span class="ml-1 font-mono text-base font-bold tabular-nums text-gray-950 dark:text-white">
                        {{ $group['total_dispensed_label'] }}
                    </span>
                </p>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="w-full min-w-3xl text-left text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3">Item</th>
                            <th class="px-4 py-3 text-right">Dispensed ({{ $periodLabel }})</th>
                            <th class="px-4 py-3 text-center">Sales</th>
                            <th class="px-4 py-3 text-right">{{ $report->remainingCaption() }}</th>
                            <th class="px-4 py-3 text-right">Reorder in packs</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($group['rows'] as $row)
                            <tr @class([
                                'bg-red-50/60 dark:bg-red-400/5' => $row['is_negative'],
                                'bg-amber-50/60 dark:bg-amber-400/5' => $row['is_low'] && ! $row['is_negative'],
                            ])>
                                <td class="px-4 py-3 font-semibold text-gray-950 dark:text-white">
                                    {{ $row['item_name'] }}
                                    @if ($row['is_negative'])
                                        <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide text-red-800 dark:bg-red-400/10 dark:text-red-200">Recount</span>
                                    @elseif ($row['is_low'])
                                        <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide text-amber-800 dark:bg-amber-400/10 dark:text-amber-200">Low stock</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-mono font-bold tabular-nums text-gray-950 dark:text-white">
                                    {{ $row['dispensed_label'] }}
                                </td>
                                <td class="px-4 py-3 text-center font-mono tabular-nums text-gray-500">
                                    {{ $row['sale_count'] }}
                                </td>
                                <td @class([
                                    'px-4 py-3 text-right font-mono tabular-nums',
                                    'text-danger-600 font-bold' => $row['is_negative'],
                                    'text-gray-400 italic' => ! $row['stock_tracked'],
                                ])>{{ $row['remaining_label'] }}</td>
                                <td class="px-4 py-3 text-right text-gray-500">
                                    @if ($row['remaining_packs_label'] !== null)
                                        <span class="font-mono tabular-nums">&asymp; {{ $row['remaining_packs_label'] }}</span>
                                    @else
                                        <span class="text-xs">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($group['negative_count'] > 0)
                <p class="rounded-lg bg-red-50 px-4 py-3 text-sm font-medium text-red-900 dark:bg-red-400/10 dark:text-red-200">
                    <strong>{{ $group['negative_count'] }}</strong>
                    {{ Str::plural('item', $group['negative_count']) }} shows a negative balance. Selling
                    past zero is allowed on purpose, so this is not an error &mdash; it means the shelf
                    and the system disagree. Recount and correct the stock level.
                </p>
            @endif
        </section>
    @endforeach

    @if ($pieces->isNotEmpty())
        <section class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="text-lg font-bold text-gray-950 dark:text-white">
                    Counted in pieces
                    <span class="ml-1 text-sm font-normal text-gray-500">
                        {{ $pieces->count() }} {{ Str::plural('item', $pieces->count()) }}
                    </span>
                </h2>

                <p class="max-w-xl text-xs text-gray-500">
                    Filters and wipers come off the shelf whole, so they have no dispensed amount and
                    are counted in units sold. They are listed on their own and are never added into
                    the litre or kilogram totals above.
                </p>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="w-full min-w-3xl text-left text-sm">
                    <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3">Item</th>
                            <th class="px-4 py-3 text-right">Units sold ({{ $periodLabel }})</th>
                            <th class="px-4 py-3 text-center">Sales</th>
                            <th class="px-4 py-3 text-right">{{ $report->remainingCaption() }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($pieces as $row)
                            <tr @class([
                                'bg-red-50/60 dark:bg-red-400/5' => $row['is_negative'],
                                'bg-amber-50/60 dark:bg-amber-400/5' => $row['is_low'] && ! $row['is_negative'],
                            ])>
                                <td class="px-4 py-3 font-semibold text-gray-950 dark:text-white">
                                    {{ $row['item_name'] }}
                                    @if ($row['is_negative'])
                                        <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide text-red-800 dark:bg-red-400/10 dark:text-red-200">Recount</span>
                                    @elseif ($row['is_low'])
                                        <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-bold uppercase tracking-wide text-amber-800 dark:bg-amber-400/10 dark:text-amber-200">Low stock</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-mono font-bold tabular-nums text-gray-950 dark:text-white">
                                    {{ $row['dispensed_label'] }}
                                </td>
                                <td class="px-4 py-3 text-center font-mono tabular-nums text-gray-500">
                                    {{ $row['sale_count'] }}
                                </td>
                                <td @class([
                                    'px-4 py-3 text-right font-mono tabular-nums',
                                    'text-danger-600 font-bold' => $row['is_negative'],
                                    'text-gray-400 italic' => ! $row['stock_tracked'],
                                ])>{{ $row['remaining_label'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-filament-panels::page>
