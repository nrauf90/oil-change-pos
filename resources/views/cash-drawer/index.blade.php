@extends('layouts.app')
@section('title', 'Cash Drawer')

@php
    /** Every figure below is money already charged or already paid out. */
    $money = fn ($amount) => number_format((float) $amount, 2);
@endphp

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-black tracking-tight">Cash Drawer</h1>
        <p class="mt-1 text-sm font-medium text-slate-500">
            {{ $isSingleDay ? 'Shift reconciliation for' : 'Reconciliation for' }}
            <span class="font-bold text-slate-700">{{ $label }}</span>
        </p>
    </div>

    <div class="no-print flex flex-wrap gap-2">
        <a href="{{ route('cash-drawer.index') }}" class="btn-ghost">Today</a>
        <button type="button" onclick="window.print()" class="btn-dark">Print</button>
    </div>
</div>

<form method="GET" action="{{ route('cash-drawer.index') }}" class="card no-print mb-6 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="label" for="from">From</label>
        <input id="from" name="from" type="date" class="field" value="{{ $from->toDateString() }}">
    </div>
    <div>
        <label class="label" for="to">To</label>
        <input id="to" name="to" type="date" class="field" value="{{ $to->toDateString() }}">
    </div>
    <button type="submit" class="btn-primary">Reconcile</button>
    <p class="ml-auto max-w-md text-xs font-medium text-slate-500">
        Leave both blank for today. One date on its own reconciles that single day.
    </p>
</form>

{{-- The three numbers the manager counts the drawer against. --}}
<div class="mb-6 grid gap-4 lg:grid-cols-3">
    <div class="card border-emerald-300 bg-emerald-50/70 p-5">
        <span class="text-xs font-bold uppercase tracking-widest text-emerald-700">Cash In</span>
        <div class="mt-2 font-mono text-4xl font-black tabular-nums text-emerald-900 lg:text-5xl">{{ $money($cashIn) }}</div>
        <p class="mt-1 text-sm font-semibold text-emerald-700">
            {{ $saleCount }} {{ Str::plural('sale', $saleCount) }} finalised
        </p>
    </div>

    <div class="card border-red-300 bg-red-50/70 p-5">
        <span class="text-xs font-bold uppercase tracking-widest text-red-700">Cash Out</span>
        <div class="mt-2 font-mono text-4xl font-black tabular-nums text-red-900 lg:text-5xl">{{ $money($cashOut) }}</div>
        <p class="mt-1 text-sm font-semibold text-red-700">
            {{ $expenseCount }} {{ Str::plural('expense', $expenseCount) }} logged
        </p>
    </div>

    <div class="card p-5 {{ $isShort ? 'border-red-500 bg-red-600 text-white' : 'border-slate-900 bg-slate-900 text-white' }}">
        <span class="text-xs font-bold uppercase tracking-widest {{ $isShort ? 'text-red-100' : 'text-amber-400' }}">
            Net Cash Position
        </span>
        <div class="mt-2 font-mono text-5xl font-black tabular-nums lg:text-6xl">{{ $money($net) }}</div>
        <p class="mt-1 text-sm font-semibold {{ $isShort ? 'text-red-100' : 'text-slate-300' }}">
            {{ $isShort ? 'The shop paid out more than it took in.' : 'This is what should be in the drawer.' }}
        </p>
    </div>
</div>

@if (! $hasActivity)
    <div class="card p-12 text-center">
        <x-filament::icon icon="heroicon-o-wallet" class="mx-auto size-12 text-slate-400" />
        <p class="mt-4 text-xl font-black tracking-tight">Nothing through the drawer for {{ $label }}</p>
        <p class="mt-1 text-sm font-medium text-slate-500">
            No sales were finalised and no expenses were logged in this period.
        </p>
        <div class="no-print mt-6 flex flex-wrap justify-center gap-3">
            @can('expenses.create')
                <a href="{{ route('expenses.create') }}" class="btn-primary">Log an expense</a>
            @endcan
            @if (Route::has('pos.create'))
                @can('pos.use')
                    <a href="{{ route('pos.create') }}" class="btn-ghost">Start a sale</a>
                @endcan
            @endif
        </div>
    </div>
@else
    <div class="grid gap-6 xl:grid-cols-2">
        <div class="card p-5">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 class="text-lg font-black tracking-tight">Cash out by category</h2>
                <p class="font-mono text-lg font-bold tabular-nums text-red-700">{{ $money($cashOut) }}</p>
            </div>

            @if ($breakdown === [])
                <p class="mt-6 text-center font-semibold text-slate-400">No expenses logged in this period.</p>
            @else
                <ul class="mt-4 space-y-3">
                    @foreach ($breakdown as $row)
                        <li>
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="pill {{ $row['badge'] }}">{{ $row['label'] }}</span>
                                <span class="text-xs font-bold uppercase tracking-wide text-slate-400">
                                    {{ $row['count'] }} {{ Str::plural('entry', $row['count']) }}
                                </span>
                                <span class="ml-auto font-mono text-lg font-bold tabular-nums text-slate-900">
                                    {{ $money($row['amount']) }}
                                </span>
                            </div>
                            <div class="mt-1.5 h-2.5 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-red-500" style="width: {{ max($row['percent'], 1.5) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-4 border-t-2 border-slate-100 pt-3 text-xs font-medium text-slate-500">
                    These rows add up to Cash Out exactly &mdash; every figure is summed in whole paisa.
                </p>
            @endif
        </div>

        <div class="card overflow-x-auto">
            <div class="flex flex-wrap items-end justify-between gap-3 p-5 pb-3">
                <h2 class="text-lg font-black tracking-tight">Expenses in this period</h2>
                @can('expenses.view_any')
                    <a href="{{ route('expenses.index', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}"
                       class="btn-ghost no-print !px-3 !py-1.5">Open list</a>
                @endcan
            </div>

            <table class="w-full text-left">
                <thead class="border-y-2 border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Spent at</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">Description</th>
                        <th class="px-4 py-3">Logged by</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($expenses as $expense)
                        <tr>
                            <td class="px-4 py-3 font-mono text-sm tabular-nums text-slate-600">
                                {{ $expense->spent_at->format($isSingleDay ? 'H:i' : 'j M H:i') }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="pill {{ $expense->category->badgeClasses() }}">{{ $expense->category->label() }}</span>
                            </td>
                            <td class="px-4 py-3 font-semibold">{{ $expense->description ?: '—' }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-500">{{ $expense->user?->name ?? 'Removed user' }}</td>
                            <td class="px-4 py-3 text-right font-mono text-lg font-bold tabular-nums text-red-700">
                                {{ $money($expense->amount) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center font-semibold text-slate-400">
                                No expenses logged in this period.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
