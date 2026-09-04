@extends('layouts.app')
@section('title', 'Draft bills')

@php
    $money = fn ($amount) => number_format((float) $amount, 2);
@endphp

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-black tracking-tight">Draft Bills</h1>
        <p class="mt-1 text-sm font-medium text-slate-500">
            Work still on the ramp. Nothing here is billed or printed until it is completed.
        </p>
    </div>
    @can('pos.use')
        <a href="{{ route('pos.create') }}" class="btn-primary">+ New sale</a>
    @endcan
</div>

<div class="card overflow-x-auto">
    <table class="w-full text-left">
        <thead class="bg-slate-50 text-xs font-bold uppercase text-slate-500">
            <tr>
                <th class="px-4 py-3">Bill</th>
                <th class="px-4 py-3">Vehicle</th>
                <th class="px-4 py-3">Started by</th>
                <th class="px-4 py-3">Opened</th>
                <th class="px-4 py-3 text-right">Lines</th>
                <th class="px-4 py-3 text-right">Running total</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($orders as $order)
                <tr>
                    <td class="px-4 py-3 font-black">
                        {{ $order->displayLabel() }}
                        @if ($order->isStale())
                            <span class="pill ml-1 bg-amber-100 text-amber-800"
                                  title="Open longer than usual — still on the ramp?">Stale</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm font-medium text-slate-600">
                        {{ $order->vehicle_model ?: '—' }}
                    </td>
                    <td class="px-4 py-3 text-sm font-medium text-slate-500">
                        {{ $order->user?->name ?? 'Removed user' }}
                    </td>
                    <td class="px-4 py-3 font-mono text-sm text-slate-600">
                        {{ $order->created_at->format('d M, g:i A') }}
                    </td>
                    <td class="px-4 py-3 text-right font-mono text-sm tabular-nums">{{ $order->lines_count }}</td>
                    <td class="px-4 py-3 text-right font-mono font-bold tabular-nums text-slate-700">
                        {{ $money($order->lines_total) }}
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex justify-end gap-2">
                            @can('draft_sales.create')
                                <a href="{{ route('pos.create', ['order' => $order->id]) }}" class="btn-ghost">Open</a>
                            @endcan
                            @can('draft_sales.delete')
                                <form method="POST" action="{{ route('orders.destroy', $order) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-ghost text-red-700">Discard</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-12 text-center font-semibold text-slate-400">
                        No draft bills open. Start one from the counter and save it when the car goes on the ramp.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($orders->hasPages())
    <div class="mt-5">{{ $orders->links() }}</div>
@endif
@endsection
