@extends('layouts.app')
@section('title', 'Suppliers & Payables')
@php
    $money = fn ($amount) => number_format((float) $amount, 2);
@endphp
@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-black tracking-tight">Suppliers &amp; Payables</h1>
        <p class="mt-1 text-sm font-medium text-slate-500">Every delivery, paper bill, payment, and outstanding balance.</p>
    </div>
    <a href="{{ route('suppliers.create') }}" class="btn-primary">+ Add supplier</a>
</div>
<form method="GET" class="card mb-5 flex gap-3 p-4">
    <input name="q" class="field" value="{{ $search }}" placeholder="Search supplier, contact, or phone">
    <button class="btn-dark">Search</button>
    @if ($search !== '') <a href="{{ route('suppliers.index') }}" class="btn-ghost">Clear</a> @endif
</form>
<div class="card overflow-x-auto">
    <table class="w-full text-left">
        <thead class="border-b-2 border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500">
            <tr><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">Contact</th><th class="px-4 py-3 text-right">Supplies</th><th class="px-4 py-3 text-right">Total supplied</th><th class="px-4 py-3 text-right">We owe</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($suppliers as $supplier)
                @php
                    $total = $supplier->supplies->sum(fn ($supply) => (float) $supply->total_amount);
                    $due = $supplier->supplies->sum(fn ($supply) => (float) $supply->balanceDue());
                @endphp
                <tr class="hover:bg-amber-50/60">
                    <td class="px-4 py-3"><a class="font-bold text-slate-900 hover:text-amber-700" href="{{ route('suppliers.show', $supplier) }}">{{ $supplier->name }}</a></td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ $supplier->contact_person ?: '—' }}<span class="block text-xs text-slate-400">{{ $supplier->phone }}</span></td>
                    <td class="px-4 py-3 text-right font-mono">{{ $supplier->supplies->count() }}</td>
                    <td class="px-4 py-3 text-right font-mono font-bold">{{ $money($total) }}</td>
                    <td class="px-4 py-3 text-right font-mono text-lg font-black {{ $due > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $money($due) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-12 text-center font-semibold text-slate-400">No suppliers found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $suppliers->links() }}</div>
@endsection
