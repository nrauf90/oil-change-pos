@extends('layouts.app')
@section('title', 'Vehicle Service History')

@section('content')
<div class="mb-5">
    <h1 class="text-3xl font-black tracking-tight">Vehicle Service History</h1>
    <p class="mt-1 text-sm font-medium text-slate-500">
        What was done to this car last time, and at what mileage.
    </p>
</div>

<form method="GET" action="{{ route('service-history.index') }}" class="card mb-6 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-64 flex-1">
        <label class="label" for="q">Phone number, licence plate or customer</label>
        <input id="q" name="q" type="search" class="field !py-3.5 !text-lg" autofocus
               inputmode="text" autocomplete="off" enterkeyhint="search"
               placeholder="0300-123 4567 &nbsp;&middot;&nbsp; ABC-123" value="{{ $search }}">
    </div>
    <button type="submit" class="btn-primary !px-8 !py-3.5 !text-base">Look up</button>
    @if ($searched)
        <a href="{{ route('service-history.index') }}" class="btn-ghost !py-3.5">Clear</a>
    @endif
</form>

@if (! $searched)
    <div class="card px-6 py-16 text-center">
        <p class="text-2xl font-black text-slate-400">Enter a phone number or licence plate</p>
        <p class="mt-2 text-sm font-medium text-slate-400">
            Dashes, spaces and a country code are all fine &mdash; 0300-123 4567 and +92 300 1234567
            find the same customer.
        </p>
    </div>
@elseif ($visits->isEmpty())
    <div class="card px-6 py-16 text-center">
        <p class="text-2xl font-black text-slate-400">No previous visits</p>
        <p class="mt-2 text-sm font-medium text-slate-400">
            Nothing on record for &ldquo;{{ $search }}&rdquo;. This looks like a first visit.
        </p>
    </div>
@else
    <p class="mb-3 text-sm font-bold uppercase tracking-wide text-slate-500">
        {{ $visits->count() }} previous {{ Str::plural('visit', $visits->count()) }}, newest first
    </p>

    <div class="space-y-4">
        @foreach ($visits as $visit)
            <article class="card overflow-hidden">
                <header class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b-2 border-slate-200 bg-slate-50 px-4 py-3">
                    <div>
                        <p class="text-lg font-black tracking-tight">
                            {{ $visit->created_at?->format('d M Y') }}
                            <span class="ml-2 font-mono text-sm font-bold text-slate-500">{{ $visit->invoice_number }}</span>
                        </p>
                        <p class="text-sm font-semibold text-slate-600">
                            {{ $visit->customer_name ?: 'Walk-in' }}
                            @if ($visit->phone)
                                <span class="text-slate-400">&middot;</span> {{ $visit->phone }}
                            @endif
                        </p>
                    </div>

                    <div class="text-right">
                        <p class="text-base font-black">{{ $visit->vehicle_plate ?: '—' }}</p>
                        <p class="text-sm font-semibold text-slate-600">
                            {{ $visit->vehicle_model ?: 'Vehicle not recorded' }}
                            @if ($visit->mileage !== null)
                                <span class="text-slate-400">&middot;</span>
                                <span class="font-mono tabular-nums">{{ number_format($visit->mileage) }} km</span>
                            @endif
                        </p>
                    </div>
                </header>

                <div class="px-4 py-3">
                    <p class="label">Work performed</p>
                    @if ($visit->lines->isEmpty())
                        <p class="text-sm font-semibold text-slate-400">No line items recorded.</p>
                    @else
                        <ul class="divide-y divide-slate-100">
                            @foreach ($visit->lines as $line)
                                <li class="flex items-center justify-between gap-4 py-2">
                                    <span class="flex items-center gap-2">
                                        <span class="pill {{ $line->type?->badgeClasses() }}">{{ $line->type?->label() }}</span>
                                        <span class="text-base font-bold">{{ $line->item_name }}</span>
                                    </span>
                                    {{-- PRD §1: a technician may read the history but never a price. --}}
                                    @can('pricing.view')
                                        <span class="font-mono text-base font-bold tabular-nums">
                                            {{ number_format((float) $line->manually_charged_price, 2) }}
                                        </span>
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($visit->notes)
                        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">
                            {{ $visit->notes }}
                        </p>
                    @endif
                </div>

                @can('pricing.view')
                    <footer class="flex flex-wrap justify-end gap-x-8 gap-y-1 border-t-2 border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold">
                        <span class="text-slate-600">Labor
                            <span class="ml-2 font-mono tabular-nums text-slate-900">{{ number_format((float) $visit->labor_charge, 2) }}</span>
                        </span>
                        <span class="text-slate-600">Misc
                            <span class="ml-2 font-mono tabular-nums text-slate-900">{{ number_format((float) $visit->misc_charge, 2) }}</span>
                        </span>
                        <span class="text-slate-900">Total
                            <span class="ml-2 font-mono text-base tabular-nums">{{ number_format((float) $visit->total_amount, 2) }}</span>
                        </span>
                    </footer>
                @endcan
            </article>
        @endforeach
    </div>
@endif
@endsection
