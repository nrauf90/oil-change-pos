@extends('layouts.app')
@section('title', 'Supply '.$supply->reference_number)
@php
    $money = fn ($amount) => number_format((float) $amount, 2);
@endphp
@section('content')
<div class="mb-5"><a class="text-sm font-bold text-slate-500 hover:text-slate-900" href="{{ route('suppliers.show', $supplier) }}">&larr; {{ $supplier->name }}</a><h1 class="mt-1 text-3xl font-black tracking-tight">Supply Detail</h1><p class="text-sm font-medium text-slate-500">Received {{ $supply->received_at->format('d M Y') }}{{ $supply->reference_number ? ' · Bill '.$supply->reference_number : '' }}</p></div>
<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <div class="card p-5"><h2 class="text-lg font-black">Items received</h2><p class="mt-3 whitespace-pre-line text-sm font-medium text-slate-700">{{ $supply->items_received }}</p>@if($supply->notes)<p class="mt-4 border-t pt-4 text-sm text-slate-500">{{ $supply->notes }}</p>@endif @if($supply->bill_image_path)<a target="_blank" class="btn-ghost mt-4 inline-flex" href="{{ route('suppliers.supplies.bill', [$supplier, $supply]) }}">View paper bill image</a>@endif</div>
        <div class="card overflow-x-auto">
            <div class="border-b px-5 py-4"><h2 class="text-lg font-black">Payment history</h2></div>
            <table class="w-full text-left"><thead class="bg-slate-50 text-xs font-bold uppercase text-slate-500"><tr><th class="px-4 py-3">Paid at</th><th class="px-4 py-3">Method</th><th class="px-4 py-3">Reference / receipt</th><th class="px-4 py-3">Recorded by</th><th class="px-4 py-3 text-right">Amount</th></tr></thead><tbody class="divide-y">
                @forelse($supply->payments as $payment)<tr><td class="px-4 py-3 font-mono text-sm">{{ $payment->paid_at->format('d M Y H:i') }}</td><td class="px-4 py-3"><span class="pill bg-slate-100 text-slate-700">{{ $payment->method->label() }}</span></td><td class="px-4 py-3 text-sm">{{ $payment->reference_number ?: '—' }}@if($payment->receipt_image_path)<a target="_blank" class="block font-bold text-amber-700" href="{{ route('suppliers.supplies.payments.receipt', [$supplier, $supply, $payment]) }}">View receipt</a>@endif</td><td class="px-4 py-3 text-sm text-slate-500">{{ $payment->user?->name ?? 'Removed user' }}</td><td class="px-4 py-3 text-right font-mono font-bold text-emerald-700">{{ $money($payment->amount) }}</td></tr>
                @empty<tr><td colspan="5" class="px-4 py-8 text-center font-semibold text-slate-400">No payments recorded yet.</td></tr>@endforelse
            </tbody></table>
        </div>
    </div>
    <aside class="space-y-5">
        <div class="card p-5"><div class="flex justify-between"><span class="label">Bill total</span><strong class="font-mono">{{ $money($supply->total_amount) }}</strong></div><div class="mt-3 flex justify-between"><span class="label">Paid</span><strong class="font-mono text-emerald-700">{{ $money($supply->paidAmount()) }}</strong></div><div class="mt-4 flex justify-between border-t pt-4"><span class="font-black">Balance due</span><strong class="font-mono text-2xl text-red-700">{{ $money($supply->balanceDue()) }}</strong></div></div>
        @unless($supply->isPaid())
        <form method="POST" enctype="multipart/form-data" action="{{ route('suppliers.supplies.payments.store', [$supplier, $supply]) }}" class="card p-5">@csrf<h2 class="text-lg font-black">Add payment</h2><div class="mt-4 space-y-4">
            <div><label class="label" for="amount">Amount</label><input id="amount" name="amount" class="field-money" inputmode="decimal" required value="{{ old('amount') }}">@error('amount')<p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="method">Paid by</label><select id="method" name="method" class="field" required><option value="">Choose method…</option>@foreach(\App\Enums\PaymentMethod::cases() as $method)<option value="{{ $method->value }}" @selected(old('method') === $method->value)>{{ $method->label() }}</option>@endforeach</select>@error('method')<p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="paid_at">Payment date &amp; time</label><input id="paid_at" name="paid_at" type="datetime-local" class="field" required value="{{ old('paid_at', now()->format('Y-m-d\TH:i')) }}">@error('paid_at')<p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="payment_reference_number">Transaction / reference</label><input id="payment_reference_number" name="reference_number" class="field" value="{{ old('reference_number') }}"></div>
            <div><label class="label" for="receipt_image">Receipt screenshot</label><input id="receipt_image" name="receipt_image" type="file" accept="image/jpeg,image/png,image/webp" class="field">@error('receipt_image')<p class="mt-1 text-sm font-bold text-red-600">{{ $message }}</p>@enderror</div>
            <div><label class="label" for="payment_notes">Notes</label><textarea id="payment_notes" name="notes" class="field" rows="2">{{ old('notes') }}</textarea></div>
            <button class="btn-primary w-full">Record payment</button>
        </div></form>
        @else<div class="card border-emerald-200 bg-emerald-50 p-5 text-center font-black text-emerald-800">This supply is fully paid.</div>@endunless
    </aside>
</div>
@endsection
