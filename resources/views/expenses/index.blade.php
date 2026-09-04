@extends('layouts.app')
@section('title', 'Expenses')

@php
    $money = fn ($amount) => number_format((float) $amount, 2);
@endphp

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-black tracking-tight">Daily Expenses</h1>
        <p class="mt-1 text-sm font-medium text-slate-500">Every rupee that left the drawer, and who logged it.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        @if (Route::has('cash-drawer.index'))
            @can('expenses.view_cash_drawer')
                <a href="{{ route('cash-drawer.index') }}" class="btn-ghost">Cash drawer</a>
            @endcan
        @endif
        @can('expenses.create')
            <a href="{{ route('expenses.create') }}" class="btn-primary">+ Log expense</a>
        @endcan
    </div>
</div>

<form method="GET" action="{{ route('expenses.index') }}" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    <div>
        <label class="label" for="from">From</label>
        <input id="from" name="from" type="date" class="field" value="{{ $filters['from']?->toDateString() }}">
    </div>
    <div>
        <label class="label" for="to">To</label>
        <input id="to" name="to" type="date" class="field" value="{{ $filters['to']?->toDateString() }}">
    </div>
    <div class="min-w-52">
        <label class="label" for="category">Category</label>
        <select id="category" name="category" class="field">
            <option value="">All categories</option>
            @foreach ($categories as $category)
                <option value="{{ $category->value }}" @selected($filters['category'] === $category->value)>{{ $category->label() }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="btn-dark">Filter</button>
    @if ($isFiltered)
        <a href="{{ route('expenses.index') }}" class="btn-ghost">Clear</a>
    @endif

    {{-- The running total is for the whole filtered set, not just this page. --}}
    <div class="ml-auto text-right">
        <span class="label">{{ $isFiltered ? 'Filtered total' : 'Total logged' }}</span>
        <p class="font-mono text-3xl font-black tabular-nums text-red-700">{{ $money($filteredTotal) }}</p>
        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">
            {{ $filteredCount }} {{ Str::plural('expense', $filteredCount) }}
        </p>
    </div>
</form>

<div class="card overflow-x-auto">
    <table class="w-full text-left">
        <thead class="border-b-2 border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3">Spent at</th>
                <th class="px-4 py-3">Category</th>
                <th class="px-4 py-3">Description</th>
                <th class="px-4 py-3">Logged by</th>
                <th class="px-4 py-3">Paid by</th>
                <th class="px-4 py-3 text-right">Amount</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($expenses as $expense)
                <tr class="hover:bg-amber-50/60">
                    <td class="px-4 py-3 font-mono text-sm tabular-nums text-slate-600">
                        {{ $expense->spent_at->format('D j M Y') }}
                        <span class="block text-xs text-slate-400">{{ $expense->spent_at->format('H:i') }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="pill {{ $expense->category->badgeClasses() }}">{{ $expense->category->label() }}</span>
                    </td>
                    <td class="px-4 py-3 font-semibold">
                        {{ $expense->description ?: '—' }}
                        @if ($expense->receipts_count > 0)
                            <span class="pill ml-1 inline-flex items-center gap-1 bg-emerald-100 text-emerald-800"
                                  title="{{ $expense->receipts_count }} receipt(s) on file">
                                <x-heroicon-o-paper-clip class="h-3.5 w-3.5" aria-hidden="true"/>
                                {{ $expense->receipts_count }}
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm font-medium text-slate-500">{{ $expense->user?->name ?? 'Removed user' }}</td>
                    <td class="px-4 py-3 text-sm font-semibold text-slate-600">{{ $expense->payment_method?->label() ?? 'Cash' }}</td>
                    <td class="px-4 py-3 text-right font-mono text-lg font-bold tabular-nums text-red-700">
                        {{ $money($expense->amount) }}
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex justify-end gap-2">
                            @can('expenses.update')
                                <a href="{{ route('expenses.edit', $expense) }}" class="btn-ghost !px-3 !py-1.5">Edit</a>
                            @endcan
                            @can('expenses.delete')
                                <x-confirm-delete :action="route('expenses.destroy', $expense)"
                                                  :subject="$expense->category->label().' expense'" />
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-12 text-center font-semibold text-slate-400">
                        No expenses logged{{ $isFiltered ? ' in this range' : ' yet' }}.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $expenses->links() }}</div>
@endsection
