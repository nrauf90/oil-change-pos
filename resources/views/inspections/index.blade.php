@extends('layouts.app')
@section('title', 'Inspections')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-black tracking-tight">Inspections</h1>
        <p class="mt-1 text-sm font-medium text-slate-500">
            Multi-point condition reports from the workshop floor. No prices &mdash; condition only.
        </p>
    </div>
    @can('inspections.create')
        <a href="{{ route('inspections.create') }}" class="btn-primary !px-6 !py-3.5">+ New inspection</a>
    @endcan
</div>

<form method="GET" action="{{ route('inspections.index') }}" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-56 flex-1">
        <label class="label" for="plate">Find by licence plate</label>
        <input id="plate" name="plate" type="search" class="field !py-3.5 !text-lg" autocomplete="off"
               enterkeyhint="search" placeholder="ABC-123" value="{{ $plate }}">
    </div>
    <button type="submit" class="btn-dark !py-3.5">Search</button>
    @if ($plate !== '')
        <a href="{{ route('inspections.index') }}" class="btn-ghost !py-3.5">Clear</a>
    @endif
</form>

<div class="card overflow-hidden">
    <table class="w-full text-left">
        <thead class="border-b-2 border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3">Vehicle</th>
                <th class="px-4 py-3">Customer</th>
                <th class="px-4 py-3">Inspected</th>
                <th class="px-4 py-3">Findings</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($inspections as $inspection)
                <tr class="hover:bg-amber-50/60">
                    <td class="px-4 py-3">
                        <p class="text-base font-black">{{ $inspection->vehicle_plate }}</p>
                        <p class="text-sm font-medium text-slate-500">
                            {{ $inspection->vehicle_model ?: '—' }}
                            @if ($inspection->mileage !== null)
                                <span class="text-slate-400">&middot;</span>
                                <span class="font-mono tabular-nums">{{ number_format($inspection->mileage) }} km</span>
                            @endif
                        </p>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-base font-bold">{{ $inspection->customer_name ?: 'Walk-in' }}</p>
                        <p class="text-sm font-medium text-slate-500">{{ $inspection->phone ?: '' }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-base font-bold">{{ $inspection->inspected_at?->format('d M Y') }}</p>
                        <p class="text-sm font-medium text-slate-500">{{ $inspection->inspector?->name ?? 'Unknown' }}</p>
                    </td>
                    <td class="px-4 py-3">
                        @if ($inspection->hasUrgentConcern())
                            <span class="pill bg-red-100 text-red-800">Urgent</span>
                        @elseif ($inspection->concernCount() > 0)
                            <span class="pill bg-amber-100 text-amber-900">{{ $inspection->concernCount() }} to watch</span>
                        @else
                            <span class="pill bg-emerald-100 text-emerald-800">All clear</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex justify-end gap-2">
                            <a href="{{ route('inspections.show', $inspection) }}" class="btn-ghost !px-3 !py-2">View</a>
                            @can('inspections.update')
                                <a href="{{ route('inspections.edit', $inspection) }}" class="btn-dark !px-3 !py-2">Edit</a>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center font-semibold text-slate-400">
                        @if ($plate !== '')
                            No inspections logged yet for &ldquo;{{ $plate }}&rdquo;.
                        @else
                            No inspections logged yet. Start one from the workshop floor.
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $inspections->links() }}</div>
@endsection
