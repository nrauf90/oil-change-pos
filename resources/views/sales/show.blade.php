@extends('layouts.app')
@section('title', $sale->invoice_number)

@section('content')
<div class="mx-auto max-w-3xl space-y-4">

    <div class="no-print flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('pos.create') }}" class="btn-primary">+ Start another sale</a>

        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn-dark" onclick="window.print()">Print</button>
            <a href="{{ route('sales.pdf', $sale) }}" class="btn-ghost">Download PDF</a>
            @if ($url = $sale->whatsappUrl())
                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="btn-ghost">Send on WhatsApp</a>
            @endif
            <a href="{{ route('sales.index') }}" class="btn-ghost">All sales</a>
        </div>
    </div>

    <article class="card p-6 sm:p-8">
        <header class="flex flex-wrap items-start justify-between gap-4 border-b-4 border-slate-900 pb-5">
            <div>
                <h1 class="text-2xl font-black tracking-tight">{{ config('app.name') }}</h1>
                <p class="mt-1 text-sm font-semibold text-slate-500">Oil change &amp; auto repair</p>
            </div>
            <div class="text-right">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-500">Invoice</p>
                <p class="font-mono text-lg font-black">{{ $sale->invoice_number }}</p>
                <p class="mt-1 text-sm font-semibold text-slate-500">{{ $sale->created_at->format('d M Y, g:i A') }}</p>
            </div>
        </header>

        <section class="grid gap-x-8 gap-y-3 border-b-2 border-slate-200 py-5 sm:grid-cols-2">
            @foreach ([
                'Customer' => $sale->customer_name ?: 'Walk-in',
                'Mobile' => $sale->phone ?: '—',
                'Vehicle' => $sale->vehicle_model ?: '—',
                'Plate' => $sale->vehicle_plate ?: '—',
                'Mileage' => $sale->mileage !== null ? number_format($sale->mileage).' km' : '—',
                'Served by' => $sale->cashier?->name ?: '—',
            ] as $label => $value)
                <div class="flex justify-between gap-4 text-sm">
                    <span class="font-bold uppercase tracking-wide text-slate-500">{{ $label }}</span>
                    <span class="text-right font-bold">{{ $value }}</span>
                </div>
            @endforeach
        </section>

        <table class="w-full py-5 text-left">
            <thead>
                <tr class="border-b-2 border-slate-200 text-xs font-bold uppercase tracking-wide text-slate-500">
                    <th class="py-3">Description</th>
                    <th class="py-3">Type</th>
                    <th class="py-3 text-right">Charged</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($sale->lines as $line)
                    <tr>
                        <td class="py-3 font-bold">
                            {{ $line->item_name }}
                            @if ($line->quantity > 1)
                                <span class="ml-1 font-mono text-sm font-semibold text-slate-500">&times; {{ $line->quantity }}</span>
                            @endif
                        </td>
                        <td class="py-3">
                            <span class="pill {{ $line->type->badgeClasses() }}">{{ $line->type->label() }}</span>
                        </td>
                        <td class="py-3 text-right font-mono font-bold tabular-nums">
                            {{ number_format((float) $line->manually_charged_price, 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="py-4 text-sm font-semibold text-slate-400">No line items — labor / misc only.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <section class="ml-auto max-w-xs space-y-2 border-t-2 border-slate-200 pt-4 text-sm font-semibold">
            <div class="flex justify-between text-slate-600">
                <span>Items &amp; repairs</span>
                <span class="font-mono tabular-nums">{{ number_format((float) $sale->lineSubtotal(), 2) }}</span>
            </div>
            <div class="flex justify-between text-slate-600">
                <span>Labor</span>
                <span class="font-mono tabular-nums">{{ number_format((float) $sale->labor_charge, 2) }}</span>
            </div>
            <div class="flex justify-between text-slate-600">
                <span>Miscellaneous</span>
                <span class="font-mono tabular-nums">{{ number_format((float) $sale->misc_charge, 2) }}</span>
            </div>
            <div class="flex items-baseline justify-between border-t-4 border-slate-900 pt-3">
                <span class="text-base font-black uppercase tracking-wide">Total paid</span>
                <span class="font-mono text-3xl font-black tabular-nums">{{ number_format((float) $sale->total_amount, 2) }}</span>
            </div>
        </section>

        @if ($sale->notes)
            <p class="mt-6 rounded-lg bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
                <span class="font-black uppercase tracking-wide text-slate-500">Note:</span> {{ $sale->notes }}
            </p>
        @endif

        <footer class="mt-8 border-t-2 border-slate-200 pt-4 text-center text-xs font-semibold text-slate-400">
            Thank you for your business. Please keep this invoice for your service record.
        </footer>
    </article>
</div>
@endsection
