@extends('layouts.app')
@section('title', 'Sales')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-black tracking-tight">Sales history</h1>
        <p class="mt-1 text-sm font-medium text-slate-500">Every invoice, at the price it was actually charged.</p>
    </div>
    <a href="{{ route('pos.create') }}" class="btn-primary">+ New sale</a>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <form method="GET" action="{{ route('sales.index') }}"
          class="flex h-14 items-center gap-3 border-b border-slate-200 bg-slate-50/80 px-4">
        <div class="relative min-w-0 flex-1">
            <span class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-slate-400">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="size-4" />
            </span>
            <label class="sr-only" for="q">Search invoices</label>
            <input id="q" name="q" type="search"
                   class="w-full rounded-lg border border-slate-300 bg-white py-2 pr-3 pl-9 text-sm font-semibold
                          placeholder:font-normal placeholder:text-slate-400
                          focus:border-amber-500 focus:ring-2 focus:ring-amber-200 focus:outline-none"
                   placeholder="Invoice, customer, phone or plate&hellip;" value="{{ $search }}">
        </div>

        <button type="submit" class="btn-dark shrink-0 !px-3 !py-2 !text-xs">Search</button>

        @if ($search)
            <a href="{{ route('sales.index') }}" class="btn-ghost shrink-0 !px-3 !py-2 !text-xs">Clear</a>
        @endif

        <span class="hidden shrink-0 font-mono text-xs font-bold tabular-nums text-slate-400 sm:inline">
            {{ $sales->total() }} {{ Str::plural('invoice', $sales->total()) }}
        </span>
    </form>

    <div class="overflow-x-auto">
        <table class="w-full min-w-3xl text-left">
            <thead class="border-b border-slate-200 bg-white text-[11px] font-bold tracking-widest text-slate-500 uppercase">
                <tr>
                    <th class="px-4 py-3 font-bold">Invoice</th>
                    <th class="px-4 py-3 font-bold">When</th>
                    <th class="px-4 py-3 font-bold">Customer</th>
                    <th class="px-4 py-3 font-bold">Vehicle</th>
                    <th class="px-4 py-3 font-bold">Served by</th>
                    <th class="px-4 py-3 text-right font-bold">Lines</th>
                    <th class="px-4 py-3 text-right font-bold">Total</th>
                    <th class="px-4 py-3 text-right font-bold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($sales as $sale)
                    <tr class="hover:bg-amber-50/60">
                        <td class="px-4 py-3 align-middle">
                            <a href="{{ route('sales.show', $sale) }}"
                               class="font-mono font-bold text-slate-900 underline decoration-amber-500 decoration-2 underline-offset-2">
                                {{ $sale->invoice_number }}
                            </a>
                        </td>
                        <td class="px-4 py-3 align-middle text-sm font-semibold whitespace-nowrap text-slate-500">
                            {{ $sale->created_at->format('d M Y, g:i A') }}
                        </td>
                        <td class="px-4 py-3 align-middle font-bold">{{ $sale->customer_name ?: 'Walk-in' }}</td>
                        <td class="px-4 py-3 align-middle text-sm font-semibold">
                            {{ $sale->vehicle_plate ?: '—' }}
                            <span class="block text-xs font-medium text-slate-400">{{ $sale->vehicle_model }}</span>
                        </td>
                        <td class="px-4 py-3 align-middle text-sm font-semibold text-slate-500">
                            {{ $sale->cashier?->name ?: '—' }}
                        </td>
                        <td class="px-4 py-3 text-right align-middle font-mono tabular-nums text-slate-500">
                            {{ $sale->lines_count }}
                        </td>
                        <td class="px-4 py-3 text-right align-middle font-mono text-lg font-black tabular-nums">
                            {{ number_format((float) $sale->total_amount, 2) }}
                        </td>
                        <td class="px-4 py-3 align-middle">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('sales.show', $sale) }}" class="btn-ghost !px-3 !py-1.5">View</a>
                                {{-- A Manager holds no sales.delete, so the button would only 403 at them. --}}
                                @can('sales.delete')
                                    <x-confirm-delete :action="route('sales.destroy', $sale)"
                                                      :subject="'invoice '.$sale->invoice_number" />
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-6 py-16 text-center">
                            <x-filament::icon icon="heroicon-o-receipt-percent" class="mx-auto size-8 text-slate-400" />
                            <p class="mt-3 font-bold text-slate-500">
                                {{ $search ? 'No invoice matches that search.' : 'No sales recorded yet.' }}
                            </p>
                            <p class="mt-1 text-sm font-medium text-slate-400">
                                {{ $search ? 'Try an invoice number, a phone number or a plate.' : 'Ring one up on the counter screen and it will appear here.' }}
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $sales->links() }}</div>
@endsection
