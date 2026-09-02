@extends('layouts.app')
@section('title', 'Vehicle Service History')

@section('content')
<div class="mb-5">
    <h1 class="text-3xl font-black tracking-tight">Vehicle Service History</h1>
    <p class="mt-1 text-sm font-medium text-slate-500">What was done to this car last time, and at what mileage.</p>
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
        {{ $vehicles->count() }} {{ Str::plural('vehicle', $vehicles->count()) }}
        <span class="text-slate-300">&middot;</span>
        {{ $visits->count() }} previous {{ Str::plural('visit', $visits->count()) }}, newest first
    </p>

    <div class="space-y-4">
        @foreach ($vehicles as $vehicleVisits)
            @php($latestVisit = $vehicleVisits->first())
            <details data-vehicle-history class="group card overflow-hidden">
                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-4 bg-slate-50 px-5 py-4 transition hover:bg-slate-100 [&::-webkit-details-marker]:hidden">
                    <span class="min-w-0">
                        <span class="block text-xl font-black tracking-tight">{{ $latestVisit->vehicle_plate ?: 'Plate not recorded' }}</span>
                        <span class="mt-0.5 block text-sm font-semibold text-slate-600">
                            {{ $latestVisit->vehicle_model ?: 'Vehicle model not recorded' }}
                            <span class="text-slate-300">&middot;</span>
                            {{ $latestVisit->customer_name ?: 'Walk-in' }}
                            @if ($latestVisit->mileage !== null)
                                <span class="text-slate-300">&middot;</span>
                                <span class="text-xs font-bold uppercase tracking-wide text-slate-400">Visit odometer reading</span>
                                <span class="font-mono tabular-nums">{{ number_format($latestVisit->mileage) }} km</span>
                            @endif
                        </span>
                    </span>

                    <span class="flex items-center gap-5 sm:gap-8">
                        <span class="text-right">
                            <span class="block text-xs font-bold uppercase tracking-wide text-slate-400">Last visit</span>
                            <span class="block font-black">{{ $latestVisit->created_at?->format('d M Y') }}</span>
                        </span>
                        <span class="text-right">
                            <span class="block text-xs font-bold uppercase tracking-wide text-slate-400">History</span>
                            <span class="block font-black">{{ $vehicleVisits->count() }} {{ Str::plural('visit', $vehicleVisits->count()) }}</span>
                        </span>
                        <span aria-hidden="true" class="grid size-9 place-items-center rounded-full bg-slate-200 text-lg font-black text-slate-600 transition group-open:rotate-180">&#9660;</span>
                    </span>
                </summary>

                <div class="border-t-2 border-slate-200">
                    @foreach ($vehicleVisits as $visit)
                        <section class="border-b-2 border-slate-200 last:border-b-0">
                            <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 bg-white px-5 py-4">
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
                                <div class="flex flex-wrap gap-4 text-right">
                                    @if ($visit->mileage !== null)
                                        <div>
                                            <p class="label">Visit odometer reading</p>
                                            <p class="font-mono text-sm font-bold tabular-nums text-slate-700">{{ number_format($visit->mileage) }} km</p>
                                        </div>
                                    @endif
                                    @if ($visit->next_checkup_mileage !== null)
                                        <div>
                                            <p class="label">Next checkup mileage</p>
                                            <p class="font-mono text-sm font-bold tabular-nums text-slate-700">{{ number_format($visit->next_checkup_mileage) }} km</p>
                                        </div>
                                    @endif
                                </div>
                            </header>

                            <div class="bg-slate-50/60 px-5 py-3">
                                <p class="label">Work performed</p>
                                @if ($visit->lines->isEmpty())
                                    <p class="text-sm font-semibold text-slate-400">No line items recorded.</p>
                                @else
                                    <ul class="divide-y divide-slate-200">
                                        @foreach ($visit->lines as $line)
                                            <li class="flex items-center justify-between gap-4 py-2">
                                                <span class="flex items-center gap-2">
                                                    <span class="pill {{ $line->type?->badgeClasses() }}">{{ $line->type?->label() }}</span>
                                                    <span class="text-base font-bold">{{ $line->item_name }}</span>
                                                </span>
                                                {{-- PRD §1: a technician may read the history but never a price. --}}
                                                @can('pricing.view')
                                                    <span class="font-mono text-base font-bold tabular-nums">{{ number_format((float) $line->manually_charged_price, 2) }}</span>
                                                @endcan
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($visit->notes)
                                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900">{{ $visit->notes }}</p>
                                @endif
                            </div>

                            @can('pricing.view')
                                <footer class="flex flex-wrap justify-end gap-x-8 gap-y-1 border-t border-slate-200 bg-slate-100 px-5 py-3 text-sm font-bold">
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
                        </section>
                    @endforeach
                </div>
            </details>
        @endforeach
    </div>
@endif
@endsection
