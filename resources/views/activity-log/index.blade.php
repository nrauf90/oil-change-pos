@extends('layouts.app')
@section('title', 'Activity Log')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-3xl font-black tracking-tight">Activity Log</h1>
        <p class="mt-1 text-sm font-medium text-slate-500">
            Every sale rung up, every record changed, every record removed &mdash; and who did it.
            Entries are written automatically and can never be edited or deleted.
        </p>
    </div>
    <p class="text-right">
        <span class="label">Entries</span>
        <span class="block font-mono text-2xl font-black tabular-nums">{{ number_format($entries->total()) }}</span>
    </p>
</div>

<form method="GET" action="{{ route('activity-log.index') }}" class="card no-print mb-5 flex flex-wrap items-end gap-3 p-4">
    <div class="min-w-56 flex-1">
        <label class="label" for="q">Search</label>
        <input id="q" name="q" type="search" class="field" placeholder="Invoice, item, customer&hellip;" value="{{ $filters['q'] }}">
    </div>
    <div class="min-w-52">
        <label class="label" for="action">Action</label>
        <select id="action" name="action" class="field">
            <option value="">All actions</option>
            @foreach ($actions as $key => $label)
                <option value="{{ $key }}" @selected($filters['action'] === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="label" for="from">From</label>
        <input id="from" name="from" type="date" class="field" value="{{ $filters['from']?->toDateString() }}">
    </div>
    <div>
        <label class="label" for="to">To</label>
        <input id="to" name="to" type="date" class="field" value="{{ $filters['to']?->toDateString() }}">
    </div>
    <button type="submit" class="btn-dark">Filter</button>
    @if ($isFiltered)
        <a href="{{ route('activity-log.index') }}" class="btn-ghost">Clear</a>
    @endif
</form>

<div class="card overflow-x-auto">
    <table class="w-full text-left">
        <thead class="border-b-2 border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3">When</th>
                <th class="px-4 py-3">Who</th>
                <th class="px-4 py-3">Action</th>
                <th class="px-4 py-3">What happened</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($entries as $entry)
                <tr class="align-top hover:bg-amber-50/60">
                    <td class="whitespace-nowrap px-4 py-3 font-mono text-sm tabular-nums text-slate-500">
                        {{ $entry->created_at?->format('d M Y H:i') ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-base font-bold">
                        {{ $entry->user_name }}
                        @unless ($entry->user)
                            <span class="pill ml-1 bg-slate-200 text-slate-600" title="This account has since been removed">
                                Former staff
                            </span>
                        @endunless
                    </td>
                    <td class="px-4 py-3">
                        <span class="pill {{ $entry->actionTone() }}">{{ $entry->actionLabel() }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-semibold">{{ $entry->description }}</p>

                        {{-- A diff, so the owner can see what the value used to be without hunting for it. --}}
                        @if ($entry->changedValues())
                            <ul class="mt-1 space-y-0.5 text-xs font-medium text-slate-500">
                                @foreach ($entry->changedValues() as $field => $change)
                                    <li class="flex flex-wrap gap-1">
                                        <span class="uppercase tracking-wide">{{ str_replace('_', ' ', $field) }}:</span>
                                        <span class="font-mono text-slate-400 line-through">{{ $change['from'] ?? '—' }}</span>
                                        <span aria-hidden="true">&rarr;</span>
                                        <span class="font-mono font-bold text-slate-700">{{ $change['to'] ?? '—' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($entry->snapshotValues())
                            <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs font-medium text-slate-500">
                                @foreach ($entry->snapshotValues() as $key => $value)
                                    <div class="flex gap-1">
                                        <dt class="uppercase tracking-wide">{{ str_replace('_', ' ', $key) }}:</dt>
                                        <dd class="font-mono text-slate-700">
                                            {{ is_bool($value) ? ($value ? 'yes' : 'no') : $value }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-4 py-12 text-center font-semibold text-slate-400">
                        @if ($isFiltered)
                            Nothing matches these filters.
                        @else
                            Nothing logged yet.
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $entries->links() }}</div>
@endsection
