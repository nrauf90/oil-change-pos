@extends('layouts.app')
@section('title', 'Inspection '.$inspection->vehicle_plate)

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-black tracking-tight">Inspection &mdash; {{ $inspection->vehicle_plate }}</h1>
        <p class="mt-1 text-sm font-medium text-slate-500">
            {{ $inspection->vehicle_model ?: 'Vehicle model not recorded' }}
            @if ($inspection->mileage !== null)
                <span class="text-slate-400">&middot;</span>
                <span class="font-mono tabular-nums">{{ number_format($inspection->mileage) }} km</span>
            @endif
        </p>
    </div>
    <div class="no-print flex gap-2">
        @can('inspections.update')
            <a href="{{ route('inspections.edit', $inspection) }}" class="btn-primary">Edit</a>
        @endcan
        <a href="{{ route('inspections.index') }}" class="btn-ghost">All inspections</a>
    </div>
</div>

<div class="card mb-5 grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
    <div>
        <p class="label">Customer</p>
        <p class="text-base font-bold">{{ $inspection->customer_name ?: 'Walk-in' }}</p>
        @if ($inspection->phone)
            <p class="text-sm font-semibold text-slate-500">{{ $inspection->phone }}</p>
        @endif
    </div>
    <div>
        <p class="label">Inspected</p>
        <p class="text-base font-bold">{{ $inspection->inspected_at?->format('d M Y, H:i') }}</p>
    </div>
    <div>
        <p class="label">Inspected by</p>
        <p class="text-base font-bold">{{ $inspection->inspector?->name ?? 'Unknown' }}</p>
    </div>
    <div>
        <p class="label">Linked invoice</p>
        {{-- Invoice number only. An inspection never carries a total. --}}
        <p class="text-base font-bold">
            @if ($inspection->sale)
                @can('sales.view')
                    <a href="{{ route('sales.show', $inspection->sale) }}" class="underline decoration-amber-500 decoration-2 underline-offset-4">
                        {{ $inspection->sale->invoice_number }}
                    </a>
                @else
                    {{ $inspection->sale->invoice_number }}
                @endcan
            @else
                &mdash;
            @endif
        </p>
    </div>
</div>

<div class="card overflow-hidden">
    <h2 class="border-b-2 border-slate-200 bg-slate-50 px-4 py-3 text-lg font-black tracking-tight">
        Check-points
        <span class="ml-2 text-sm font-bold text-slate-500">
            {{ $inspection->concernCount() }} of {{ $inspection->points->count() }} need work
        </span>
    </h2>

    <ul class="divide-y divide-slate-100">
        @forelse ($inspection->points as $item)
            <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                <span class="min-w-48 flex-1 text-lg font-bold">{{ $item->label() }}</span>
                <span class="pill {{ $item->status?->badgeClasses() }} !text-sm">{{ $item->status?->label() }}</span>
                @if ($item->note)
                    <span class="basis-full text-sm font-semibold text-slate-600 sm:basis-auto sm:flex-1 sm:text-right">
                        {{ $item->note }}
                    </span>
                @endif
            </li>
        @empty
            <li class="px-4 py-12 text-center font-semibold text-slate-400">
                No check-points were recorded on this sheet.
            </li>
        @endforelse
    </ul>

    @if ($inspection->notes)
        <div class="border-t-2 border-slate-200 px-4 py-3">
            <p class="label">Overall note</p>
            <p class="text-base font-semibold text-slate-800">{{ $inspection->notes }}</p>
        </div>
    @endif
</div>
@endsection
