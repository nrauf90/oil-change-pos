@extends('layouts.app')
@section('title', $supplier->name)
@php
    $money = fn ($amount) => number_format((float) $amount, 2);
    $total = $supplier->supplies->sum(fn ($supply) => (float) $supply->total_amount);
    $due = $supplier->supplies->sum(fn ($supply) => (float) $supply->balanceDue());
@endphp
@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-4">
    <div><a class="text-sm font-bold text-slate-500 hover:text-slate-900" href="{{ route('suppliers.index') }}">&larr; Suppliers</a><h1 class="mt-1 text-3xl font-black tracking-tight">{{ $supplier->name }}</h1><p class="text-sm font-medium text-slate-500">{{ collect([$supplier->contact_person, $supplier->phone, $supplier->email])->filter()->join(' · ') }}</p></div>
    <div class="flex gap-2"><a class="btn-ghost" href="{{ route('suppliers.edit', $supplier) }}">Edit supplier</a><a class="btn-primary" href="{{ route('suppliers.supplies.create', $supplier) }}">+ Add supply</a></div>
</div>
<div class="mb-5 grid gap-4 sm:grid-cols-3">
    <div class="card p-4"><span class="label">Supply entries</span><p class="text-3xl font-black">{{ $supplier->supplies->count() }}</p></div>
    <div class="card p-4"><span class="label">Total supplied</span><p class="font-mono text-3xl font-black">{{ $money($total) }}</p></div>
    <div class="card border-red-200 p-4"><span class="label">Outstanding</span><p class="font-mono text-3xl font-black {{ $due > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $money($due) }}</p></div>
</div>
<div class="card overflow-x-auto">
    <table class="w-full text-left">
        <thead class="border-b-2 border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Received</th><th class="px-4 py-3">Supply / bill</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-right">Paid</th><th class="px-4 py-3 text-right">Balance</th><th class="px-4 py-3">Status</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($supplier->supplies as $supply)
                <tr class="hover:bg-amber-50/60">
                    <td class="px-4 py-3 font-mono text-sm">{{ $supply->received_at->format('d M Y') }}</td>
                    <td class="max-w-md px-4 py-3"><a class="font-bold hover:text-amber-700" href="{{ route('suppliers.supplies.show', [$supplier, $supply]) }}">{{ Str::limit($supply->items_received, 70) }}</a><span class="block text-xs text-slate-400">{{ $supply->reference_number ?: 'No bill reference' }}</span></td>
                    <td class="px-4 py-3 text-right font-mono font-bold">{{ $money($supply->total_amount) }}</td><td class="px-4 py-3 text-right font-mono text-emerald-700">{{ $money($supply->paidAmount()) }}</td><td class="px-4 py-3 text-right font-mono font-black text-red-700">{{ $money($supply->balanceDue()) }}</td>
                    <td class="px-4 py-3"><span class="pill {{ $supply->isPaid() ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">{{ $supply->isPaid() ? 'Paid' : 'Payment due' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-12 text-center font-semibold text-slate-400">No supplies recorded for this supplier.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
