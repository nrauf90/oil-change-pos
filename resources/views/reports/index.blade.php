@extends('layouts.app')
@section('title', 'Dashboard')

@php
    /** Every figure below is money already charged and stored on the sale. */
    $money = fn ($amount) => number_format((float) $amount, 2);
@endphp

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-black tracking-tight">Dashboard</h1>
        <p class="mt-1 text-sm font-medium text-slate-500">
            Revenue exactly as it was charged at the counter &mdash; never a reference cost.
        </p>
    </div>

    <nav class="no-print flex flex-wrap gap-1 rounded-xl bg-slate-200 p-1" aria-label="Breakdown period">
        @foreach ($periods as $value => $label)
            <a href="{{ route('reports.index', ['period' => $value]) }}"
               @if ($period === $value) aria-current="page" @endif
               class="rounded-lg px-4 py-2 text-sm font-bold uppercase tracking-wide transition
                      {{ $period === $value ? 'bg-slate-900 text-white shadow' : 'text-slate-600 hover:bg-white' }}">
                {{ $label }}
            </a>
        @endforeach
    </nav>
</div>

<div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
    @foreach ($headline as $key => $card)
        <div class="card p-5 {{ $key === 'today' ? 'border-amber-400 bg-amber-50/70' : '' }}">
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-xs font-bold uppercase tracking-widest text-slate-500">{{ $card['label'] }}</span>
                <span class="font-mono text-xs text-slate-400 tabular-nums">{{ $card['range'] }}</span>
            </div>
            <div class="mt-2 font-mono text-4xl font-black tabular-nums lg:text-5xl">{{ $money($card['revenue']) }}</div>
            <div class="mt-1 text-sm font-semibold text-slate-500">
                {{ $card['count'] }} {{ Str::plural('sale', $card['count']) }}
            </div>
        </div>
    @endforeach
</div>

@if (! $hasSales)
    <div class="card p-12 text-center">
        <x-filament::icon icon="heroicon-o-clipboard-document-list" class="mx-auto size-12 text-slate-400" />
        <p class="mt-4 text-xl font-black tracking-tight">No sales recorded yet</p>
        <p class="mt-1 text-sm font-medium text-slate-500">
            Ring up the first bill on the sale screen and the numbers will appear here.
        </p>
        @if (Route::has('pos.create'))
            <a href="{{ route('pos.create') }}" class="btn-primary no-print mt-6 inline-block">Start a sale</a>
        @endif
    </div>
@else
    <div class="card mb-6 p-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h2 class="text-lg font-black tracking-tight">{{ $windowLabel }}</h2>
            <p class="text-sm font-semibold text-slate-500">
                <span class="font-mono tabular-nums text-slate-900">{{ $money($windowRevenue) }}</span>
                across {{ $windowSaleCount }} {{ Str::plural('sale', $windowSaleCount) }}
            </p>
        </div>

        {{-- Pure-CSS bar chart: no external script is ever loaded on the counter machine. --}}
        <div class="mt-5 flex h-48 items-end gap-1 border-b-2 border-slate-200 sm:gap-2">
            @foreach ($breakdown as $bucket)
                <div class="flex h-full flex-1 flex-col justify-end" title="{{ $bucket['label'] }}: {{ $money($bucket['revenue']) }}">
                    <div class="mb-1 text-center font-mono text-[10px] font-bold tabular-nums text-slate-500">
                        {{ $bucket['count'] ?: '' }}
                    </div>
                    <div class="rounded-t {{ $bucket['percent'] > 0 ? 'bg-amber-500' : 'bg-slate-200' }}"
                         style="height: {{ max($bucket['percent'], 1.5) }}%"></div>
                </div>
            @endforeach
        </div>
        <div class="mt-2 flex gap-1 sm:gap-2">
            @foreach ($breakdown as $bucket)
                <div class="flex-1 text-center text-[10px] font-bold uppercase leading-tight text-slate-400">
                    {{ $bucket['label'] }}
                </div>
            @endforeach
        </div>

        <div class="mt-5 overflow-x-auto">
            <table class="w-full text-left">
                <thead class="border-b-2 border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">{{ $bucketNoun }}</th>
                        <th class="px-4 py-3 text-right">Sales</th>
                        <th class="px-4 py-3 text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($breakdown as $bucket)
                        <tr class="{{ $bucket['count'] === 0 ? 'text-slate-400' : 'hover:bg-amber-50/60' }}">
                            <td class="px-4 py-2.5 text-base font-bold">{{ $bucket['label'] }}</td>
                            <td class="px-4 py-2.5 text-right font-mono tabular-nums">{{ $bucket['count'] }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-base font-bold tabular-nums">
                                {{ $money($bucket['revenue']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="card p-5">
            <h2 class="text-lg font-black tracking-tight">Where the money came from</h2>
            <p class="mt-1 text-sm font-medium text-slate-500">{{ $windowLabel }}, from stored charges only.</p>

            <dl class="mt-4 space-y-3">
                @foreach ($split as $row)
                    <div>
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-sm font-bold uppercase tracking-wide text-slate-600">{{ $row['label'] }}</dt>
                            <dd class="font-mono text-lg font-black tabular-nums">{{ $money($row['amount']) }}</dd>
                        </div>
                        <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-slate-700" style="width: {{ $row['percent'] }}%"></div>
                        </div>
                    </div>
                @endforeach
            </dl>

            <div class="mt-4 flex items-baseline justify-between gap-3 border-t-2 border-slate-200 pt-3">
                <span class="text-sm font-black uppercase tracking-wide">Total charged</span>
                <span class="font-mono text-2xl font-black tabular-nums">{{ $money($windowRevenue) }}</span>
            </div>
        </div>

        <div class="card p-5">
            <h2 class="text-lg font-black tracking-tight">Top items sold</h2>
            <p class="mt-1 text-sm font-medium text-slate-500">By how often the line was charged.</p>

            <table class="mt-4 w-full text-left">
                <thead class="border-b-2 border-slate-200 text-xs font-bold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="py-2">Item</th>
                        <th class="py-2 text-right">Times</th>
                        <th class="py-2 text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($topItems as $row)
                        <tr>
                            <td class="py-2.5 pr-3">
                                <div class="text-base font-bold">{{ $row['item_name'] }}</div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-amber-500" style="width: {{ $row['percent'] }}%"></div>
                                </div>
                            </td>
                            <td class="py-2.5 text-right align-top font-mono text-base font-bold tabular-nums">
                                {{ $row['times_charged'] }}
                            </td>
                            <td class="py-2.5 text-right align-top font-mono text-base font-bold tabular-nums">
                                {{ $money($row['revenue']) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-10 text-center font-semibold text-slate-400">
                                Nothing charged in this window.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
